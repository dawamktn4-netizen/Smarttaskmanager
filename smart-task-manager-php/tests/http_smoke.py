#!/usr/bin/env python3
"""Real HTTP checks against an already running SmartTask installation.

Uses only Python's standard library. Creates two random QA accounts and touches
only their data. Passwords live in memory and are never printed or written.
No database access or automatic account deletion is performed. At exit, a report
contains the exact test account emails and a narrowly scoped teardown statement.

Usage: python3 tests/http_smoke.py --base-url http://localhost/smart-task-manager-php
Optional: --report /tmp/smarttask-http-results.json
"""
import argparse
import datetime as dt
import html
from html.parser import HTMLParser
import http.cookiejar
import json
import os
from pathlib import Path
import re
import secrets
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from zoneinfo import ZoneInfo


class FormParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.forms = []
        self.form = None
        self.select = None
        self.option = None
        self.textarea = None
        self.meta_csrf = None
        self.links = []

    def handle_starttag(self, tag, pairs):
        attrs = dict(pairs)
        if tag == "meta" and attrs.get("name") == "csrf-token":
            self.meta_csrf = attrs.get("content")
        if tag == "a":
            self.links.append(attrs.get("href", ""))
        if tag == "form":
            self.form = {"action": attrs.get("action", ""), "method": attrs.get("method", "get").lower(), "fields": {}, "options": {}, "buttons": []}
            self.forms.append(self.form)
        if self.form is None:
            return
        name = attrs.get("name")
        if tag == "input" and name:
            if attrs.get("type") not in ("checkbox", "radio") or "checked" in attrs:
                self.form["fields"][name] = attrs.get("value", "on" if attrs.get("type") == "checkbox" else "")
        if tag == "select" and name:
            self.select = name
            self.form["options"][name] = []
        if tag == "option" and self.select:
            self.option = {"value": attrs.get("value"), "text": "", "selected": "selected" in attrs}
        if tag == "textarea" and name:
            self.textarea = name
            self.form["fields"][name] = ""
        if tag == "button" and name:
            self.form["buttons"].append({"name": name, "value": attrs.get("value", "")})

    def handle_data(self, data):
        if self.option is not None:
            self.option["text"] += data
        if self.form is not None and self.textarea:
            self.form["fields"][self.textarea] += data

    def handle_endtag(self, tag):
        if tag == "option" and self.option is not None and self.form is not None:
            self.option["value"] = self.option["value"] if self.option["value"] is not None else self.option["text"].strip()
            self.form["options"][self.select].append(self.option)
            self.option = None
        if tag == "select" and self.select and self.form is not None:
            options = self.form["options"][self.select]
            if options:
                selected = next((x for x in options if x["selected"]), options[0])
                self.form["fields"][self.select] = selected["value"]
            self.select = None
        if tag == "textarea":
            self.textarea = None
        if tag == "form":
            self.form = None


class Page:
    def __init__(self, response):
        self.status = response.code
        self.url = response.geturl()
        self.headers = response.headers
        self.body = response.read().decode("utf-8", errors="replace")
        self.parsed = FormParser()
        self.parsed.feed(self.body)

    @property
    def csrf(self):
        if self.parsed.meta_csrf:
            return self.parsed.meta_csrf
        for form in self.parsed.forms:
            if form["fields"].get("csrf"):
                return form["fields"]["csrf"]
        raise AssertionError("No CSRF token present in response")

    def form(self, required_field):
        return next(f for f in self.parsed.forms if required_field in f["fields"])

    def json(self):
        return json.loads(self.body)


class Client:
    def __init__(self, base):
        self.base = base.rstrip("/") + "/"
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar))

    def request(self, path, data=None, headers=None):
        url = urllib.parse.urljoin(self.base, path)
        payload = urllib.parse.urlencode(data).encode() if data is not None else None
        request = urllib.request.Request(url, data=payload, headers=headers or {})
        try:
            response = self.opener.open(request, timeout=20)
        except urllib.error.HTTPError as exc:
            response = exc
        page = Page(response)
        if re.search(r"(?:Fatal error:|Warning:|Parse error:|Uncaught (?:Error|Exception))", page.body):
            raise AssertionError("PHP error surfaced at " + urllib.parse.urlparse(page.url).path)
        return page

    def session_id(self):
        return next((c.value for c in self.jar if c.name == "PHPSESSID"), None)


class Suite:
    def __init__(self, args):
        self.args = args
        self.run_id = secrets.token_hex(7)
        self.accounts = []
        self.results = []
        self.tz = ZoneInfo("Europe/Copenhagen")
        self.a = Client(args.base_url)
        self.b = Client(args.base_url)

    def check(self, name, condition):
        self.results.append({"name": name, "passed": bool(condition)})
        print(("PASS " if condition else "FAIL ") + name, flush=True)
        if not condition:
            raise AssertionError(name)

    def local_future(self, *, days=0, seconds=0):
        return (dt.datetime.now(self.tz) + dt.timedelta(days=days, seconds=seconds)).strftime("%Y-%m-%dT%H:%M:%S")

    def register(self, client, suffix):
        email = "smarttask.qa." + self.run_id + "." + suffix + "@example.invalid"
        password = secrets.token_urlsafe(24)
        page = client.request("register.php")
        fields = {"csrf": page.csrf, "full_name": "QA " + self.run_id + " " + suffix, "email": email, "password": password, "timezone": "Europe/Copenhagen"}
        if suffix == "a":
            rejected = client.request("register.php", {**fields, "csrf": "invalid"})
            self.check("Signup rejects an invalid CSRF token", rejected.status == 403)
        page = client.request("register.php", fields)
        self.check("Register isolated QA account " + suffix, page.status == 200 and urllib.parse.urlparse(page.url).path.endswith("/login.php"))
        self.accounts.append(email)
        print("QA_ACCOUNT " + email, flush=True)
        session_before = client.session_id()
        page = client.request("login.php", {"csrf": page.csrf, "email": email, "password": password})
        self.check("Sign in account " + suffix, page.status == 200 and urllib.parse.urlparse(page.url).path.endswith("/dashboard.php"))
        self.check("Session ID changes on login " + suffix, bool(session_before) and session_before != client.session_id())
        return page

    def tasks_for(self, client, title):
        page = client.request("tasks.php?" + urllib.parse.urlencode({"status": "ALL", "q": title}))
        ids = sorted(set(int(m.group(1)) for link in page.parsed.links for m in [re.search(r"edit_task\.php\?id=(\d+)", link)] if m))
        return page, ids

    def create_task(self, client, label, **updates):
        page = client.request("add_task.php")
        form = page.form("title")
        categories = [x["value"] for x in form["options"]["category_id"] if x["value"]]
        title = "QA " + self.run_id + " " + label
        fields = {**form["fields"], "title": title, "description": "Isolated HTTP test record.", "category_id": categories[0] if categories else "", "due_at": self.local_future(days=4), "importance": "HIGH", "status": "PENDING", **updates}
        saved = client.request("add_task.php", fields)
        self.check("Create task: " + label, saved.status == 200 and urllib.parse.urlparse(saved.url).path.endswith("/tasks.php"))
        _, ids = self.tasks_for(client, fields["title"])
        self.check("Created task is persisted: " + label, len(ids) == 1)
        return ids[0], fields["title"]

    def state(self, client, task_id):
        page = client.request("edit_task.php?id=" + str(task_id))
        return page, page.form("title")["fields"]

    def action(self, client, task_id, action, csrf=None):
        if csrf is None:
            csrf = client.request("tasks.php").csrf
        return client.request("task_action.php", {"csrf": csrf, "id": task_id, "action": action})

    def settings(self, **updates):
        page = self.a.request("settings.php")
        fields = dict(page.form("quiet_start")["fields"])
        fields.update(updates)
        for key in [key for key, value in fields.items() if value is None]:
            del fields[key]
        return self.a.request("settings.php", fields)

    def add_category(self, client, suffix):
        page = client.request("settings.php")
        name = "QA category " + self.run_id + " " + suffix
        response = client.request("settings.php", {"csrf": page.csrf, "action": "add_category", "category_name": name})
        self.check("Create personal category " + suffix, "Category added." in response.body)
        form = client.request("add_task.php").form("title")
        category_id = next(x["value"] for x in form["options"]["category_id"] if x["text"] == name)
        return category_id, name

    def run(self):
        anon = Client(self.args.base_url)
        page = anon.request("dashboard.php")
        self.check("Protected page redirects anonymous users", page.status == 200 and urllib.parse.urlparse(page.url).path.endswith("/login.php"))
        dashboard = self.register(self.a, "a")
        self.register(self.b, "b")
        self.check("Security headers sent", dashboard.headers.get("X-Content-Type-Options") == "nosniff" and dashboard.headers.get("X-Frame-Options") == "DENY" and "no-store" in dashboard.headers.get("Cache-Control", ""))
        for path in ["dashboard.php", "tasks.php", "assistant.php", "reminders.php", "analytics.php", "history.php", "settings.php"]:
            page = self.a.request(path)
            self.check("Authenticated page loads: " + path, page.status == 200 and "data-authenticated=\"true\"" in page.body)

        own_category, own_category_name = self.add_category(self.a, "owned")
        foreign_category, foreign_category_name = self.add_category(self.b, "foreign")
        page = self.a.request("add_task.php")
        own_options = page.form("title")["options"]["category_id"]
        self.check("Category list hides another account's categories", all(x["value"] != foreign_category for x in own_options))
        settings_page = self.a.request("settings.php")
        response = self.a.request("settings.php", {"csrf": settings_page.csrf, "action": "delete_category", "category_id": foreign_category})
        self.check("Category deletion cannot affect another account", foreign_category_name in self.b.request("settings.php").body)
        response = self.a.request("settings.php", {"csrf": settings_page.csrf, "action": "add_category", "category_name": own_category_name})
        self.check("Duplicate personal category rejected", "That category already exists." in response.body)
        response = self.a.request("settings.php", {"csrf": "invalid", "action": "delete_category", "category_id": own_category})
        self.check("Settings mutations require CSRF", response.status == 403)

        page = self.a.request("add_task.php")
        fields = page.form("title")["fields"]
        invalid_title = "QA " + self.run_id + " must not persist"
        bad = {**fields, "title": invalid_title, "importance": "HIGH", "status": "PENDING", "category_id": foreign_category, "due_at": self.local_future(days=1)}
        response = self.a.request("add_task.php", bad)
        self.check("Task creation rejects another account's category", response.status == 200 and "Choose one of your categories." in response.body)
        _, absent = self.tasks_for(self.a, invalid_title)
        self.check("Rejected category creates no task", not absent)
        response = self.a.request("add_task.php", {**bad, "category_id": "", "due_at": "2026-02-30T10:00"})
        self.check("Invalid calendar date rejected", "Enter a valid date and time." in response.body)
        response = self.a.request("add_task.php", {**bad, "category_id": "", "csrf": "invalid"})
        self.check("Task creation rejects invalid CSRF", response.status == 403)
        _, absent = self.tasks_for(self.a, invalid_title)
        self.check("Invalid forms leave no persisted task", not absent)

        xss_title = "QA " + self.run_id + ' <script>alert("qa")</script>'
        task_id, title = self.create_task(self.a, "main", title=xss_title, category_id=own_category)
        page, _ = self.tasks_for(self.a, title)
        self.check("Task title is HTML escaped", html.escape(title, quote=False) in page.body.replace("&quot;", '"') and '<script>alert("qa")</script>' not in page.body)
        foreign_id, foreign_title = self.create_task(self.b, "foreign account task")
        for method in ["get", "post"]:
            response = self.a.request("edit_task.php?id=" + str(foreign_id), None if method == "get" else {"csrf": self.a.request("tasks.php").csrf, "title": "Changed by other user"})
            self.check("Cross-account task edit denied via " + method.upper(), response.status == 404)
        response = self.action(self.a, foreign_id, "delete")
        self.check("Cross-account task deletion denied", response.status == 404)
        _, foreign_state = self.state(self.b, foreign_id)
        self.check("Foreign task remains unchanged", foreign_state["title"] == foreign_title)
        _, absent = self.tasks_for(self.a, foreign_title)
        self.check("Task search cannot expose another account's tasks", not absent)

        for action in ["complete", "postpone", "delete"]:
            response = self.a.request("task_action.php?" + urllib.parse.urlencode({"id": task_id, "action": action}))
            self.check("GET cannot mutate task: " + action, response.status == 405)
        self.check("GET logout is rejected", self.a.request("logout.php").status == 405)
        self.check("Task mutation rejects invalid CSRF", self.action(self.a, task_id, "complete", csrf="invalid").status == 403)
        _, state = self.state(self.a, task_id)
        self.check("Rejected actions leave task pending", state["status"] == "PENDING")

        new_title = "QA " + self.run_id + " edited task"
        saved = self.a.request("edit_task.php?id=" + str(task_id), {**state, "title": new_title, "description": "Updated notes <b>stay text</b>", "importance": "LOW"})
        self.check("Edit task succeeds", urllib.parse.urlparse(saved.url).path.endswith("/tasks.php"))
        _, state = self.state(self.a, task_id)
        self.check("Edited values persist", state["title"] == new_title and state["importance"] == "LOW" and state["description"] == "Updated notes <b>stay text</b>")
        response = self.a.request("edit_task.php?id=" + str(task_id), {**state, "category_id": foreign_category})
        self.check("Editing rejects another account's category", "Choose one of your categories." in response.body)
        _, state = self.state(self.a, task_id)
        old_due = dt.datetime.fromisoformat(state["due_at"])
        self.action(self.a, task_id, "postpone")
        _, state = self.state(self.a, task_id)
        self.check("Postpone advances deadline by one local day", dt.datetime.fromisoformat(state["due_at"]) == old_due + dt.timedelta(days=1))
        self.action(self.a, task_id, "start")
        self.check("Start transitions task to in progress", self.state(self.a, task_id)[1]["status"] == "IN_PROGRESS")
        self.action(self.a, task_id, "complete")
        self.check("Complete transitions task to completed", self.state(self.a, task_id)[1]["status"] == "COMPLETED")
        self.action(self.a, task_id, "reopen")
        self.check("Reopen transitions task to pending", self.state(self.a, task_id)[1]["status"] == "PENDING")
        history = self.a.request("history.php")
        self.check("Task changes appear in activity history", "Postpon" in history.body and new_title in history.body)
        assistant = self.a.request("assistant.php")
        self.check("Assistant exposes actual explanations", new_title in assistant.body and "Why this score?" in assistant.body and "Postponement pattern" in assistant.body)

        page = self.a.request("settings.php")
        self.a.request("settings.php", {"csrf": page.csrf, "action": "delete_category", "category_id": own_category})
        self.check("Deleting own category preserves task as uncategorised", self.state(self.a, task_id)[1]["category_id"] == "")

        self.reminder_checks(task_id, foreign_id)

        self.action(self.a, task_id, "delete")
        self.check("Owner can delete their task", self.a.request("edit_task.php?id=" + str(task_id)).status == 404)
        csrf = self.a.request("dashboard.php").csrf
        self.check("Logout rejects invalid CSRF", self.a.request("logout.php", {"csrf": "invalid"}).status == 403)
        signed_out = self.a.request("logout.php", {"csrf": csrf})
        self.check("POST logout succeeds", urllib.parse.urlparse(signed_out.url).path.endswith("/login.php"))
        self.check("Session no longer accesses dashboard", urllib.parse.urlparse(self.a.request("dashboard.php").url).path.endswith("/login.php"))

    def reminder_checks(self, task_id, foreign_id):
        def reminder_ids(page):
            return set(int(f["fields"]["reminder_id"]) for f in page.parsed.forms if "reminder_id" in f["fields"])

        def action(kind, **fields):
            page = self.a.request("reminders.php")
            return self.a.request("reminder_action.php", {"csrf": page.csrf, "action": kind, **fields})

        def scheduled(kind, label, **fields):
            before = reminder_ids(self.a.request("reminders.php"))
            response = action(kind, **fields)
            after = reminder_ids(response)
            self.check(label, response.status == 200 and len(after - before) == 1)
            return (after - before).pop(), response

        def feed(client=None):
            page = (client or self.a).request("notifications.php")
            self.check("Notification feed responds with JSON", page.status == 200 and page.headers.get_content_type() == "application/json")
            return page.json()

        def ack(reminder_id, client=None):
            client = client or self.a
            return client.request("notifications.php", {"csrf": client.request("reminders.php").csrf, "action": "ack", "reminder_id": reminder_id}).json()

        anon = Client(self.args.base_url)
        self.check("Notification API requires login", anon.request("notifications.php").status == 401)
        self.check("Reminder GET mutations are rejected", self.a.request("reminder_action.php?action=create&task_id=" + str(task_id)).status == 405)
        self.check("Reminder POST mutations require CSRF", self.a.request("reminder_action.php", {"action": "create", "task_id": task_id, "csrf": "invalid"}).status == 403)
        self.check("Notification acknowledgement requires CSRF", self.a.request("notifications.php", {"action": "ack", "reminder_id": 1, "csrf": "invalid"}).status == 403)
        self.check("API rejects unsupported action", self.a.request("notifications.php", {"action": "delete", "reminder_id": 1, "csrf": self.a.request("reminders.php").csrf}).status == 400)

        prefs = self.settings(quiet_start="00:00", quiet_end="00:00", notifications_enabled="1", preferred_hour="12")
        self.check("Preferences save and persist preferred hour", prefs.form("quiet_start")["fields"]["preferred_hour"] == "12")
        self.check("Account timezone is shown consistently", "Europe/Copenhagen" in prefs.body and "Europe/Copenhagen" in self.a.request("reminders.php").body)
        invalid = self.settings(quiet_start="25:90")
        self.check("Invalid quiet-hour input rejected", "Choose valid quiet-hour times." in invalid.body)
        invalid = self.settings(preferred_hour="24")
        self.check("Invalid preferred hour rejected", "Choose a preferred reminder hour" in invalid.body)
        self.check("Quiet-hour validation did not alter saved preferences", self.a.request("settings.php").form("quiet_start")["fields"]["quiet_start"] == "00:00")
        response = action("create", task_id=foreign_id, reminder_at=self.local_future(days=1), message="Forbidden")
        self.check("Reminders cannot be created for another account's task", "This task is unavailable" in response.body)
        response = action("create", task_id=task_id, reminder_at="2001-01-01T10:00", message="Past")
        self.check("Past reminder time rejected", "Choose a reminder time in the future." in response.body)
        response = action("create", task_id=task_id, reminder_at=self.local_future(days=1), message="x" * 501)
        self.check("Oversized reminder message rejected", "500 characters or fewer" in response.body)

        future_time = self.local_future(days=2)
        manual_id, response = scheduled("create", "Manual reminder is created", task_id=task_id, reminder_at=future_time, message="QA manual " + self.run_id)
        self.check("Manual reminder preserves explicit message", "QA manual " + self.run_id in response.body)
        self.check("Future reminder cannot be acknowledged early", ack(manual_id)["acknowledged"] is False)
        duplicate = action("create", task_id=task_id, reminder_at=future_time, message="QA manual " + self.run_id)
        self.check("Duplicate manual submission is rejected", "already scheduled" in duplicate.body)
        early_snooze = action("snooze", reminder_id=manual_id)
        self.check("Future reminder cannot be snoozed early", "when it becomes due" in early_snooze.body)
        foreign_cancel = self.b.request("reminder_action.php", {"csrf": self.b.request("reminders.php").csrf, "action": "cancel", "reminder_id": manual_id})
        self.check("Another account cannot cancel a reminder", "unavailable or already closed" in foreign_cancel.body and manual_id in reminder_ids(self.a.request("reminders.php")))
        self.action(self.a, task_id, "complete")
        self.check("Completing task disables its reminders", manual_id not in reminder_ids(self.a.request("reminders.php")))
        self.action(self.a, task_id, "reopen")
        self.check("Reopening does not reactivate old reminder", manual_id not in reminder_ids(self.a.request("reminders.php")))

        accept_task, _ = self.create_task(self.a, "AI accept")
        accepted_id, response = scheduled("accept", "AI suggestion can be accepted", task_id=accept_task, reminder_at="2001-01-01T00:00", message="UNTRUSTED SUBMITTED AI MESSAGE")
        self.check("AI acceptance uses server-generated message", "UNTRUSTED SUBMITTED AI MESSAGE" not in response.body and "AI · accepted by you" in response.body)
        self.check("Accepting same AI suggestion twice is rejected", "already been reviewed" in action("accept", task_id=accept_task).body)
        action("cancel", reminder_id=accepted_id)
        self.check("Accepted reminder can be cancelled", accepted_id not in reminder_ids(self.a.request("reminders.php")))
        change_task, _ = self.create_task(self.a, "AI change")
        changed_id, response = scheduled("change", "AI suggestion can be adjusted", task_id=change_task, reminder_at=self.local_future(days=2), message="QA adjusted " + self.run_id)
        self.check("Adjusted suggestion preserves user's message", "QA adjusted " + self.run_id in response.body and "AI · adjusted by you" in response.body)
        ignore_task, _ = self.create_task(self.a, "AI ignore")
        ignored = action("ignore", task_id=ignore_task)
        self.check("AI suggestion can be ignored", "Suggestion ignored." in ignored.body and 'id="suggestion-' + str(ignore_task) + '"' not in ignored.body)
        self.check("Ignored suggestion is not offered again", 'id="suggestion-' + str(ignore_task) + '"' not in self.a.request("reminders.php").body)

        # Seconds are accepted by the server parser. Keeping this wait below ten
        # seconds makes the real delivery-state checks fast and reproducible.
        due_at = self.local_future(seconds=6)
        wait_until = time.monotonic() + 7
        due_id, _ = scheduled("create", "Soon-due reminder created for delivery checks", task_id=task_id, reminder_at=due_at, message="QA due " + self.run_id)
        self.settings(notifications_enabled=None)
        paused = feed()
        self.check("Disabled notifications pause delivery", paused["paused"] is True and paused["reminders"] == [])
        while time.monotonic() < wait_until:
            time.sleep(min(0.25, max(0, wait_until - time.monotonic())))
        self.check("Paused acknowledgement is deferred", ack(due_id).get("deferred") is True)
        local_now = dt.datetime.now(self.tz)
        quiet_start = (local_now - dt.timedelta(minutes=30)).strftime("%H:%M")
        quiet_end = (local_now + dt.timedelta(minutes=30)).strftime("%H:%M")
        self.settings(notifications_enabled="1", quiet_start=quiet_start, quiet_end=quiet_end)
        quiet = feed()
        self.check("Account-local quiet interval suppresses due delivery", quiet["quiet"] is True and quiet["paused"] is False and quiet["reminders"] == [])
        self.check("Quiet-hour acknowledgement is deferred", ack(due_id).get("deferred") is True)
        self.settings(quiet_start="00:00", quiet_end="00:00")
        first = feed()
        second = feed()
        first_ids = [r["reminder_id"] for r in first["reminders"]]
        second_ids = [r["reminder_id"] for r in second["reminders"]]
        self.check("Due reminder delivered after quiet hours disabled", due_id in first_ids and first["quiet"] is False)
        self.check("Reading feed has no acknowledgement side effect", due_id in second_ids and first_ids == second_ids)
        matching = next(r for r in first["reminders"] if r["reminder_id"] == due_id)
        self.check("Feed preserves account-local scheduled datetime", matching["reminder_at"] == due_at.replace("T", " "))
        self.check("Notification feed IDs have integer JSON types", isinstance(matching["reminder_id"], int) and isinstance(matching["task_id"], int))
        self.check("Another account cannot acknowledge this reminder", ack(due_id, self.b)["acknowledged"] is False)
        self.check("Owner acknowledges a displayed reminder", ack(due_id)["acknowledged"] is True)
        self.check("Acknowledgement is idempotent", ack(due_id)["acknowledged"] is False)
        self.check("Acknowledged reminder leaves pending feed", due_id not in [r["reminder_id"] for r in feed()["reminders"]])
        snoozed = action("snooze", reminder_id=due_id)
        self.check("Displayed reminder can be snoozed", "Snoozed for 15 minutes." in snoozed.body and due_id in reminder_ids(snoozed))
        self.check("Snoozed reminder is not immediately delivered", due_id not in [r["reminder_id"] for r in feed()["reminders"]])
        dismissed = action("dismiss", reminder_id=due_id)
        self.check("Reminder can be dismissed without deleting task", "Reminder dismissed." in dismissed.body and due_id not in reminder_ids(dismissed) and self.state(self.a, task_id)[1]["status"] == "PENDING")
        action("cancel", reminder_id=changed_id)

    def report(self, error=None):
        escaped = ["'" + email.replace("'", "''") + "'" for email in self.accounts]
        teardown = "DELETE FROM users WHERE email IN (" + ", ".join(escaped) + ");" if escaped else "-- No QA account was created."
        data = {"run_id": self.run_id, "base_url": self.args.base_url, "qa_accounts": self.accounts, "checks": self.results, "passed": not error and all(x["passed"] for x in self.results), "error": str(error) if error else None, "teardown_sql_review_before_running": teardown,
            "scope_note": "Only newly created QA accounts and their personal categories/tasks/reminders are modified. Real browser permission prompts are not automated. HTTP clients do not execute page JavaScript."}
        if self.args.report:
            Path(self.args.report).write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
        print("\n" + ("PASS" if data["passed"] else "FAIL") + ": " + str(sum(x["passed"] for x in self.results)) + " of " + str(len(self.results)) + " HTTP checks passed.")
        if error:
            print("Failure: " + str(error))
        print("Exact QA-account cleanup (review and run separately):\n" + teardown)
        return data


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--base-url", default=os.environ.get("SMARTTASK_BASE_URL", "http://localhost/smart-task-manager-php"))
    parser.add_argument("--report", help="Optional JSON report path; contains QA identities, never passwords or session cookies.")
    args = parser.parse_args()
    suite = Suite(args)
    error = None
    try:
        suite.run()
    except Exception as exc:
        error = exc
    suite.report(error)
    return 1 if error else 0


if __name__ == "__main__":
    sys.exit(main())
