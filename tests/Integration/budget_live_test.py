#!/usr/bin/env python3
"""Live test for AI budgets against a running DreamFactory stack.

Needs an OpenAI-compatible model server. Starts a local proxy in front of it
that counts provider requests, so "no provider request is made" is measured
on the wire. The proxy also receives the alert webhooks, and can return fixed
token counts, which test 8 needs to step through 50, 80 and 100 percent.

    DF_URL=http://localhost:8081 python3 tests/Integration/budget_live_test.py

The stack must run with these settings, or the preflight stops the run:
    DF_AI_BUDGET_MANAGEMENT_ENABLED=true
    DF_AI_BUDGET_WEBHOOKS_ENABLED=true
    DF_AI_BUDGET_WEBHOOK_ALLOWED_HOSTS=host.docker.internal,127.0.0.1
        (PROXY_HOST, plus 127.0.0.1 for the dead-webhook check)
    a GOLD license, or DF_AI_BUDGET_REQUIRED_LICENSE set to the stack's tier

Creates its own services, roles, apps, a user and usage rows, and removes
them on exit. Set DF_CONTAINER to the web container: the fallback chain has no
API, and cleanup removes usage rows, so both go through artisan tinker.
"""
import json, os, subprocess, sys, threading, time, traceback, urllib.error, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

DF_URL = os.environ.get("DF_URL", "http://localhost:8081").rstrip("/")
ADMIN = (os.environ.get("DF_ADMIN_EMAIL", "admin@dreamfactory.com"), os.environ.get("DF_ADMIN_PASSWORD", "passwordpassword"))
CONTAINER = os.environ.get("DF_CONTAINER", "df-development-web-1")
MODEL_URL = os.environ.get("MODEL_BASE_URL", "http://localhost:11434").rstrip("/")
CHAT_MODEL = os.environ.get("CHAT_MODEL", "qwen3:0.6b")
EMBED_MODEL = os.environ.get("EMBED_MODEL", "qwen3-embedding:0.6b")
PROXY_HOST = os.environ.get("PROXY_HOST", "host.docker.internal")
PROXY_PORT = int(os.environ.get("PROXY_PORT", "18099"))
PREFIX = "budgettest"
SECRET = "Bearer budget-test-secret-value"

state = {"canned": False, "fail_primary": False}
counts = {"primary": 0, "fallback": 0}
hooks = []
redirected = []
results = []


class Proxy(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass

    def send_json(self, status, body):
        raw = json.dumps(body).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_GET(self):
        self.send_json(200, {"object": "list", "data": []})

    def do_POST(self):
        body = self.rfile.read(int(self.headers.get("Content-Length", 0)))
        rest = self.path.lstrip("/")
        if rest == "redirect":
            redirected.append(rest)
            self.send_response(307)
            self.send_header("Location", f"http://{PROXY_HOST}:{PROXY_PORT}/hook")
            self.send_header("Content-Length", "0")
            return self.end_headers()
        if rest == "hook":
            hooks.append({"body": json.loads(body), "auth": self.headers.get("Authorization")})
            return self.send_json(200, {})
        conn = self.server.connection
        counts[conn] += 1
        if conn == "primary" and state["fail_primary"]:
            return self.send_json(503, {"error": {"message": "forced outage"}})
        if state["canned"]:
            return self.canned(rest, json.loads(body))
        request = urllib.request.Request(f"{MODEL_URL}/{rest}", body, {"Content-Type": "application/json"})
        try:
            with urllib.request.urlopen(request, timeout=120) as upstream:
                self.send_response(upstream.status)
                self.send_header("Content-Type", upstream.headers.get("Content-Type", "application/json"))
                self.end_headers()
                while chunk := upstream.read(4096):
                    self.wfile.write(chunk)
                    self.wfile.flush()
        except urllib.error.HTTPError as e:
            self.send_json(e.code, {"error": {"message": e.read().decode()[:200]}})

    def canned(self, rest, request):
        usage = {"prompt_tokens": 1000, "completion_tokens": 1000, "total_tokens": 2000}
        if rest.endswith("embeddings"):
            return self.send_json(200, {"object": "list", "model": "canned", "usage": usage,
                                        "data": [{"object": "embedding", "index": 0, "embedding": [0.1, 0.2]}]})
        message = {"role": "assistant", "content": "ok"}
        if not request.get("stream"):
            return self.send_json(200, {"id": "c", "object": "chat.completion", "model": "canned", "usage": usage,
                                        "choices": [{"index": 0, "message": message, "finish_reason": "stop"}]})
        self.send_response(200)
        self.send_header("Content-Type", "text/event-stream")
        self.end_headers()
        chunks = [
            {"id": "c", "model": "canned", "choices": [{"index": 0, "delta": message, "finish_reason": None}]},
            {"id": "c", "model": "canned", "choices": [{"index": 0, "delta": {}, "finish_reason": "stop"}], "usage": usage},
        ]
        for chunk in chunks:
            self.wfile.write(f"data: {json.dumps(chunk)}\n\n".encode())
        self.wfile.write(b"data: [DONE]\n\n")


def call(method, path, body=None, token=None, key=None):
    headers = {"Accept": "application/json", "Content-Type": "application/json"}
    if token:
        headers["X-DreamFactory-Session-Token"] = token
    if key:
        headers["X-DreamFactory-API-Key"] = key
    data = None if body is None else json.dumps(body).encode()
    request = urllib.request.Request(DF_URL + path, data, headers, method=method)
    try:
        with urllib.request.urlopen(request, timeout=180) as r:
            status, raw = r.status, r.read().decode()
    except urllib.error.HTTPError as e:
        status, raw = e.code, e.read().decode()
    try:
        return status, json.loads(raw), raw
    except ValueError:
        return status, None, raw


def check(name, passed, detail=""):
    results.append(bool(passed))
    print(f"  {'PASS' if passed else 'FAIL'} {name}" + (f"  [{detail}]" if detail and not passed else ""), flush=True)


def tinker(code):
    subprocess.run(["docker", "exec", CONTAINER, "php", "artisan", "tinker", "--execute", code],
                   check=True, capture_output=True, timeout=60)


class Stack:
    """Fixtures, tracked for removal."""

    def __init__(self):
        status, body, raw = call("POST", "/api/v2/system/admin/session", {"email": ADMIN[0], "password": ADMIN[1]})
        assert status == 200, raw
        self.token = body["session_token"]
        self.created = []

    def admin(self, method, path, body=None):
        return call(method, path, body, token=self.token)

    def create(self, kind, record):
        status, body, raw = self.admin("POST", f"/api/v2/system/{kind}?fields=*", {"resource": [record]})
        assert status in (200, 201), raw
        row = body["resource"][0]
        self.created.append((kind, row["id"]))
        return row

    def connection(self, name):
        port = PROXY_PORT + (name == "fallback")
        return self.create("service", {
            "name": f"{PREFIX}-{name}", "label": f"Budget test {name}", "type": "ai_connection", "is_active": True,
            "config": {"provider": "openai_compatible", "base_url": f"http://{PROXY_HOST}:{port}",
                       "default_model": CHAT_MODEL, "max_tokens": 16, "timeout": 120,
                       "cost_per_1k_input": 1.0, "cost_per_1k_output": 1.0},
        })["id"]

    def role_and_app(self, name, service_ids):
        access = [{"service_id": s, "component": "*", "verb_mask": 31, "requestor_mask": 3} for s in service_ids]
        role = self.create("role", {"name": f"{PREFIX}-{name}", "is_active": True, "role_service_access_by_role_id": access})
        app = self.create("app", {"name": f"{PREFIX}-{name}", "type": 0, "role_id": role["id"], "is_active": True})
        return role["id"], app["id"], app["api_key"]

    def budget(self, **fields):
        record = {"period": "monthly", "label": f"{PREFIX} rule", **fields}
        status, body, raw = self.admin("POST", "/_internal/ai/budgets", record)
        assert status == 201, raw
        return body["id"]

    def cleanup(self):
        state["canned"] = True
        status, body, _ = self.admin("GET", "/_internal/ai/budgets")
        for budget in (body or {}).get("resource", []):
            self.admin("DELETE", f"/_internal/ai/budgets/{budget['id']}")
        # ai-chat provisions one agent app and user per role it runs under.
        roles = [i for kind, i in self.created if kind == "role"]
        if roles:
            tinker(f"foreach({roles} as $r)"
                   "{DB::table('app')->where('name','ai-agent-app-role-'.$r)->delete();"
                   "DB::table('user')->where('name','AI Agent (role '.$r.')')->delete();}")
        order = {"service": 0, "app": 1, "user": 2, "role": 3}
        ids = [i for kind, i in self.created if kind == "service"]
        if ids:
            tinker(f"DB::table('ai_usage_log')->whereIn('service_id',{ids})->delete();"
                   f"DB::table('ai_model_alias')->where('name','like','{PREFIX}%')->delete();")
        for kind, row_id in sorted(reversed(self.created), key=lambda c: order[c[0]]):
            self.admin("DELETE", f"/api/v2/system/{kind}/{row_id}")


def usage_rows(stack, role_id):
    _, body, _ = stack.admin("GET", f"/_internal/ai/usage?period=1h&role_id={role_id}")
    return body["summary"]["total_requests"] if "summary" in body else body["total_requests"]


def spend_status(stack, budget_id):
    _, body, _ = stack.admin("GET", "/_internal/ai/budgets")
    return next(b for b in body["resource"] if b["id"] == budget_id)


def test_api(stack, primary, key):
    print("API: authorization")
    routes = [("GET", "/_internal/ai/budgets"), ("POST", "/_internal/ai/budgets"), ("PATCH", "/_internal/ai/budgets/1"),
              ("DELETE", "/_internal/ai/budgets/1"), ("GET", "/_internal/ai/aliases"), ("POST", "/_internal/ai/aliases"),
              ("PATCH", "/_internal/ai/aliases/1"), ("DELETE", "/_internal/ai/aliases/1")]
    for method, path in routes:
        check(f"1. {method} {path} non-admin 403", call(method, path, {}, key=key)[0] == 403)
        check(f"1. {method} {path} anonymous 403", call(method, path, {})[0] == 403)

    print("API: create, read, update, delete by dimension")
    _, roles, _ = stack.admin("GET", "/api/v2/system/role?limit=1&fields=id&filter=" + urllib.request.quote(f"name like '{PREFIX}%'"))
    _, users, _ = stack.admin("GET", "/api/v2/system/user?limit=1&fields=id&filter=" + urllib.request.quote(f"email like '{PREFIX}%'"))
    _, apps, _ = stack.admin("GET", "/api/v2/system/app?limit=1&fields=id&filter=" + urllib.request.quote(f"name like '{PREFIX}%'"))
    dimensions = {"global": None, "service": primary, "role": roles["resource"][0]["id"],
                  "user": users["resource"][0]["id"], "app": apps["resource"][0]["id"]}
    for dimension, dimension_id in dimensions.items():
        status, row, raw = stack.admin("POST", "/_internal/ai/budgets", {
            "dimension_type": dimension, "dimension_id": dimension_id, "period": "daily", "budget_usd": 12.5, "label": f"crud {dimension}"})
        check(f"2. create {dimension}", status == 201 and row["hard_stop"] is False and row["budget_usd"] == 12.5, raw)
        _, listing, _ = stack.admin("GET", "/_internal/ai/budgets")
        listed = next((b for b in listing["resource"] if b["id"] == row["id"]), {})
        check(f"2. read {dimension}", listed.get("dimension_type") == dimension and
              {"spent_usd", "remaining_usd", "utilization", "blocking", "has_alert_webhook_auth"} <= set(listed), listed)
        check(f"2. {dimension} scope has a name", dimension == "global" or listed.get("dimension_name"), listed)
        status, row, raw = stack.admin("PATCH", f"/_internal/ai/budgets/{row['id']}", {"budget_usd": 20, "hard_stop": True})
        check(f"2. update {dimension}", status == 200 and row["budget_usd"] == 20 and row["hard_stop"] is True, raw)
        status, _, raw = stack.admin("DELETE", f"/_internal/ai/budgets/{row['id']}")
        check(f"2. delete {dimension}", status == 200 and stack.admin("PATCH", f"/_internal/ai/budgets/{row['id']}", {})[0] == 404, raw)

    print("API: validation")
    good = {"dimension_type": "global", "period": "daily", "budget_usd": 5, "label": "x"}
    bad = {
        "dimension_id on global": {"dimension_id": 1},
        "unknown role": {"dimension_type": "role", "dimension_id": 99999999},
        "unknown user": {"dimension_type": "user", "dimension_id": 99999999},
        "unknown app": {"dimension_type": "app", "dimension_id": 99999999},
        "unknown service": {"dimension_type": "service", "dimension_id": 99999999},
        "non-AI service": {"dimension_type": "service", "dimension_id": 1},
        "missing role id": {"dimension_type": "role"},
        "text id": {"dimension_type": "role", "dimension_id": "abc"},
        "zero cap": {"budget_usd": 0},
        "negative cap": {"budget_usd": -1},
        "cap rounding to zero": {"budget_usd": 0.00001},
        "cap too large": {"budget_usd": 100000000},
        "text cap": {"budget_usd": "lots"},
        "bad dimension": {"dimension_type": "department"},
        "bad period": {"period": "weekly"},
        "empty label": {"label": ""},
        "long label": {"label": "x" * 257},
        "ftp webhook": {"alert_webhook_url": "ftp://x/y"},
    }
    for name, change in bad.items():
        status, _, raw = stack.admin("POST", "/_internal/ai/budgets", {**good, **change})
        check(f"3. {name} 400", status == 400, f"{status} {raw[:120]}")

    print("API: webhook auth header")
    status, row, raw = stack.admin("POST", "/_internal/ai/budgets", {**good, "alert_webhook_url": "http://x.test/h", "alert_webhook_auth_header": SECRET})
    budget_id = row["id"]
    check("4. create hides header", SECRET not in raw and "alert_webhook_auth_header" not in row and row["has_alert_webhook_auth"] is True)
    raw = stack.admin("GET", "/_internal/ai/budgets")[2]
    check("4. list hides header", SECRET not in raw and "alert_webhook_auth_header" not in raw)
    raw = stack.admin("GET", "/_internal/ai/usage?period=1h")[2]
    check("4. usage hides header", SECRET not in raw)
    _, row, raw = stack.admin("PATCH", f"/_internal/ai/budgets/{budget_id}", {"alert_webhook_auth_header": "", "label": "kept"})
    check("4. empty PATCH keeps header", row["has_alert_webhook_auth"] is True and SECRET not in raw)
    _, row, _ = stack.admin("PATCH", f"/_internal/ai/budgets/{budget_id}", {"clear_alert_webhook_auth": True})
    check("4. clear flag removes header", row["has_alert_webhook_auth"] is False)
    stack.admin("DELETE", f"/_internal/ai/budgets/{budget_id}")


def path_calls(svc, alias):
    messages = [{"role": "user", "content": "Say ok."}]
    paths = {
        "chat": (f"/api/v2/{svc}/chat", {"messages": messages}),
        "chat stream": (f"/api/v2/{svc}/chat", {"stream": True, "messages": messages}),
        "completion": (f"/api/v2/{svc}/completion", {"prompt": "Say ok."}),
        "completion stream": (f"/api/v2/{svc}/completion", {"stream": True, "prompt": "Say ok."}),
        "embeddings": (f"/api/v2/{svc}/embeddings", {"input": "hello", "model": EMBED_MODEL}),
        "data chat": (f"/api/v2/{svc}/data-chat", {"messages": messages}),
        "openai compat": ("/api/v2/_ai/v1/chat/completions", {"model": alias, "messages": messages}),
        "openai compat stream": ("/api/v2/_ai/v1/chat/completions", {"model": alias, "stream": True, "messages": messages}),
    }
    return {name: (lambda p=path, b=body: lambda key, token=None: call("POST", p, b, key=key, token=token))()
            for name, (path, body) in paths.items()}


def succeeded(result):
    status, _, raw = result
    return status == 200 and '"error"' not in raw[:200]


def test_path(stack, name, send, other_send, role_id, other_key, key):
    print(f"Enforcement: {name}")
    state["canned"] = False
    budget_id = stack.budget(dimension_type="role", dimension_id=role_id, budget_usd=1000, hard_stop=True)
    result = send(key)
    check(f"5. {name} below cap succeeds", succeeded(result), result[2][:200])
    spent = spend_status(stack, budget_id)["spent_usd"]
    check(f"5. {name} spend recorded", spent > 0, spent)

    stack.admin("PATCH", f"/_internal/ai/budgets/{budget_id}", {"budget_usd": max(round(spent / 2, 4), 0.0001)})
    check(f"6. {name} status says blocking", spend_status(stack, budget_id)["blocking"] is True)
    before, rows = dict(counts), usage_rows(stack, role_id)
    status, body, raw = send(key)
    check(f"6. {name} over cap returns 429", status == 429, f"{status} {raw[:200]}")
    check(f"6. {name} carries the budget message", "budget exceeded" in raw, raw[:200])
    check(f"6. {name} made no provider request", counts["primary"] == before["primary"], counts)
    check(f"10. {name} did not use the fallback", counts["fallback"] == before["fallback"], counts)
    check(f"7. {name} added no usage row", usage_rows(stack, role_id) == rows)

    state["canned"] = True
    result = other_send(other_key)
    check(f"9. {name} other role unaffected", succeeded(result), result[2][:200])
    stack.admin("DELETE", f"/_internal/ai/budgets/{budget_id}")


def test_alerts(stack, name, send, key, role_id):
    print(f"Soft budget and alerts: {name}")
    state["canned"] = True
    hooks.clear()
    first = send(key)
    check(f"8. {name} first call", succeeded(first), first[2][:200])
    _, usage, _ = stack.admin("GET", f"/_internal/ai/usage?period=1h&role_id={role_id}")
    cost = usage["summary"]["total_cost_usd"] if "summary" in usage else usage["total_cost_usd"]
    # Five equal steps of 0.204 cross 50, 80 and 100 percent on separate calls.
    budget_id = stack.budget(dimension_type="role", dimension_id=role_id, budget_usd=round(cost * 4.9, 4), hard_stop=False,
                             alert_webhook_url=f"http://{PROXY_HOST}:{PROXY_PORT}/hook", alert_webhook_auth_header=SECRET)
    outcomes = [succeeded(send(key)) for _ in range(5)]
    check(f"8. {name} soft budget never blocks", all(outcomes), outcomes)
    fired = [h["body"]["threshold_pct"] for h in hooks if h["body"]["budget"]["id"] == budget_id]
    check(f"8. {name} alerts fired once each", fired == [50, 80, 100], fired)
    check(f"8. {name} stored header reached the webhook", bool(hooks) and all(h["auth"] == SECRET for h in hooks), hooks[:1])
    stack.admin("DELETE", f"/_internal/ai/budgets/{budget_id}")


def test_failure_paths(stack, send, key, role_id):
    print("Failure paths and periods")
    state["canned"] = True
    _, listing, _ = stack.admin("GET", "/_internal/ai/budgets")
    check("11. list reports usage logging on", listing.get("usage_logging_enabled") is True, listing)

    for period in ("daily", "hourly"):
        budget_id = stack.budget(dimension_type="role", dimension_id=role_id, budget_usd=0.0001, hard_stop=True, period=period)
        status, _, raw = send(key)
        check(f"6. {period} cap returns 429", status == 429, f"{status} {raw[:160]}")
        stack.admin("DELETE", f"/_internal/ai/budgets/{budget_id}")

    hooks.clear()
    dead = stack.budget(dimension_type="role", dimension_id=role_id, budget_usd=0.0001, alert_webhook_url="http://127.0.0.1:9/none")
    result = send(key)
    check("8. dead webhook does not fail the AI call", succeeded(result), result[2][:200])
    stack.admin("DELETE", f"/_internal/ai/budgets/{dead}")

    corrupt = stack.budget(dimension_type="role", dimension_id=role_id, budget_usd=0.0001,
                           alert_webhook_url=f"http://{PROXY_HOST}:{PROXY_PORT}/hook", alert_webhook_auth_header=SECRET)
    tinker(f"DB::table('ai_budget')->where('id',{corrupt})->update(['alert_webhook_auth_header'=>'not-ciphertext']);")
    result = send(key)
    check("8. undecryptable header does not fail the AI call", succeeded(result), result[2][:200])
    status, listing, raw = stack.admin("GET", "/_internal/ai/budgets")
    row = next(b for b in listing["resource"] if b["id"] == corrupt)
    check("4. undecryptable header does not break the list", status == 200 and row["has_alert_webhook_auth"] is True, raw[:200])
    stack.admin("DELETE", f"/_internal/ai/budgets/{corrupt}")

    tinker("DB::table('ai_budget')->insert(['dimension_type'=>'global','period'=>'daily','budget_usd'=>1000]);")
    _, listing, _ = stack.admin("GET", "/_internal/ai/budgets")
    legacy = next(b for b in listing["resource"] if b["label"] is None)
    status, row, raw = stack.admin("PATCH", f"/_internal/ai/budgets/{legacy['id']}", {"is_active": False})
    check("2. PATCH works on a row with no label", status == 200 and row["is_active"] is False, raw[:200])
    stack.admin("DELETE", f"/_internal/ai/budgets/{legacy['id']}")

    hooks.clear()
    redirected.clear()
    budget_id = stack.budget(dimension_type="role", dimension_id=role_id, budget_usd=0.0001,
                             alert_webhook_url=f"http://{PROXY_HOST}:{PROXY_PORT}/redirect", alert_webhook_auth_header=SECRET)
    result = send(key)
    check("8. webhook redirect is not followed", succeeded(result) and len(redirected) == 1 and not hooks, (redirected, hooks))
    stack.admin("DELETE", f"/_internal/ai/budgets/{budget_id}")

    hooks.clear()
    budget_id = stack.budget(dimension_type="role", dimension_id=role_id, budget_usd=0.0001,
                             alert_webhook_url=f"http://{PROXY_HOST}:{PROXY_PORT}/hook")
    send(key), send(key)
    check("8. crossed cap alerts once", [h["body"]["threshold_pct"] for h in hooks] == [100], hooks)
    stack.admin("PATCH", f"/_internal/ai/budgets/{budget_id}", {"budget_usd": 0.0002})
    send(key)
    check("8. changed cap can alert again", len(hooks) == 2, hooks)
    stack.admin("DELETE", f"/_internal/ai/budgets/{budget_id}")


def test_dimensions(stack, primary, fallback, send, key, app_id, other_key, user_token, user_id):
    print("Enforcement by dimension (chat path)")
    state["canned"] = True
    send(key)
    send(other_key, user_token)

    def blocked_then_clear(rule, label, blocked_call, free_call):
        budget_id = stack.budget(**rule, budget_usd=0.0001, hard_stop=True)
        status = blocked_call()[0]
        check(f"6. {label} cap returns 429", status == 429, status)
        if free_call:
            check(f"9. {label} cap leaves others alone", succeeded(free_call()))
        stack.admin("DELETE", f"/_internal/ai/budgets/{budget_id}")

    blocked_then_clear({"dimension_type": "app", "dimension_id": app_id}, "app", lambda: send(key), lambda: send(other_key))
    blocked_then_clear({"dimension_type": "user", "dimension_id": user_id}, "user", lambda: send(other_key, user_token), lambda: send(key))
    blocked_then_clear({"dimension_type": "service", "dimension_id": primary}, "service", lambda: send(key), None)
    blocked_then_clear({"dimension_type": "global"}, "global", lambda: send(key), None)
    budget_id = stack.budget(dimension_type="global", budget_usd=0.0001, hard_stop=True)
    stack.admin("PATCH", f"/_internal/ai/budgets/{budget_id}", {"is_active": False})
    check("6. inactive rule does not block", succeeded(send(key)))
    stack.admin("DELETE", f"/_internal/ai/budgets/{budget_id}")

    print("Fallback connections")
    state["fail_primary"] = True
    before = counts["fallback"]
    check("10. outage still reaches an uncapped fallback", succeeded(send(key)) and counts["fallback"] == before + 1, counts)
    budget_id = stack.budget(dimension_type="service", dimension_id=fallback, budget_usd=0.0001, hard_stop=True)
    before = counts["fallback"]
    status = send(key)[0]
    check("10. outage skips a capped fallback", counts["fallback"] == before and status != 200, (status, counts))
    state["fail_primary"] = False
    stack.admin("DELETE", f"/_internal/ai/budgets/{budget_id}")


def preflight(stack):
    """Stop with the reason when the stack's settings would fail every check."""
    status, listing, raw = stack.admin("GET", "/_internal/ai/budgets")
    if status == 404:
        sys.exit("Budget management is off on this stack: set DF_AI_BUDGET_MANAGEMENT_ENABLED=true.")
    if status == 403:
        sys.exit(f"The admin may not manage budgets on this stack: {raw[:200]}")
    if status != 200:
        sys.exit(f"GET /_internal/ai/budgets returned {status}: {raw[:200]}")
    if listing.get("alert_webhooks_enabled") is False:
        sys.exit("Alert webhooks are off on this stack: set DF_AI_BUDGET_WEBHOOKS_ENABLED=true and "
                 f"DF_AI_BUDGET_WEBHOOK_ALLOWED_HOSTS={PROXY_HOST},127.0.0.1.")


def main():
    for offset, connection in enumerate(counts):
        server = ThreadingHTTPServer(("0.0.0.0", PROXY_PORT + offset), Proxy)
        server.connection = connection
        threading.Thread(target=server.serve_forever, daemon=True).start()
    stack = Stack()
    preflight(stack)
    try:
        primary, fallback = stack.connection("primary"), stack.connection("fallback")
        tinker(f"DB::table('ai_connection_config')->where('service_id',{primary})->update(['fallback_service_ids'=>'[{fallback}]']);")
        svc = f"{PREFIX}-primary"

        chat_svc = stack.create("service", {"name": f"{PREFIX}-chat", "label": "Budget test chat", "type": "ai_chat",
                                            "is_active": True, "config": {"ai_service_id": primary}})
        _, data_app, _ = stack.role_and_app("data", [int(os.environ.get("DATA_SERVICE_ID", "7"))])
        tinker(f"DB::table('ai_connection_config')->where('service_id',{primary})->update(['app_id'=>{data_app}]);Cache::flush();")

        alias = f"{PREFIX}-alias"
        status, created, raw = stack.admin("POST", "/_internal/ai/aliases", {"name": alias, "service_id": primary, "physical_model": CHAT_MODEL})
        check("5. alias created through the API", status == 201, raw)
        check("3. duplicate alias refused", stack.admin("POST", "/_internal/ai/aliases", {"name": alias, "service_id": primary, "physical_model": "x"})[0] == 400)
        check("3. alias on non-AI service refused", stack.admin("POST", "/_internal/ai/aliases", {"name": alias + "2", "service_id": 1, "physical_model": "x"})[0] == 400)

        calls = path_calls(svc, alias)
        grants = [primary, chat_svc["id"]]
        fixtures = {name: stack.role_and_app(name.replace(" ", "-"), grants) for name in [*calls, "ai chat"]}
        soft = {name: stack.role_and_app("soft-" + name.replace(" ", "-"), grants) for name in fixtures}
        other_role, other_app, other_key = stack.role_and_app("other", grants)

        def login(name, role_id, app_id, key):
            email = f"{PREFIX}-{name}@example.invalid"
            status, user, raw = stack.admin("POST", "/api/v2/system/user?fields=*", {"resource": [{
                "name": name, "email": email, "password": "budget-test-password", "is_active": True,
                "user_to_app_to_role_by_user_id": [{"app_id": app_id, "role_id": role_id}]}]})
            assert status in (200, 201), raw
            stack.created.append(("user", user["resource"][0]["id"]))
            status, session, raw = call("POST", f"/api/v2/user/session?api_key={key}", {"email": email, "password": "budget-test-password"})
            assert status == 200, raw
            return user["resource"][0]["id"], session["session_token"]

        user_id, user_session = login("other", other_role, other_app, other_key)
        chat_tokens = {other_key: user_session}
        for name, (role_id, app_id, key) in {"main": fixtures["ai chat"], "soft": soft["ai chat"]}.items():
            chat_tokens[key] = login(name, role_id, app_id, key)[1]

        allowed = json.dumps([f[0] for f in [*fixtures.values(), *soft.values()]] + [other_role])
        tinker(f"DB::table('ai_connection_config')->where('service_id',{primary})->update(['allowed_roles'=>'{allowed}']);Cache::flush();")

        sessions = {}
        def ai_chat_send(key, token=None):
            # A chat session needs a user, so this path always logs in.
            token = chat_tokens[key]
            if key not in sessions:
                status, body, raw = call("POST", f"/api/v2/{chat_svc['name']}/session", {"title": "budget test"}, key=key, token=token)
                assert status in (200, 201), raw
                sessions[key] = body["id"]
            return call("POST", f"/api/v2/{chat_svc['name']}/session/{sessions[key]}", {"message": "Say ok."}, key=key, token=token)
        calls["ai chat"] = ai_chat_send

        test_api(stack, primary, fixtures["chat"][2])
        for name, send in calls.items():
            role_id, _, key = fixtures[name]
            test_path(stack, name, send, send, role_id, other_key, key)
        for name, send in calls.items():
            soft_role, _, soft_key = soft[name]
            test_alerts(stack, name, send, soft_key, soft_role)

        chat = calls["chat"]
        test_failure_paths(stack, chat, soft["chat"][2], soft["chat"][0])
        test_dimensions(stack, primary, fallback, chat, fixtures["chat"][2], fixtures["chat"][1], other_key, user_session, user_id)
    except Exception:
        traceback.print_exc()
        results.append(False)
    finally:
        state["fail_primary"] = False
        stack.cleanup()

    failed = results.count(False)
    print(f"\n{len(results) - failed} passed, {failed} failed")
    print(f"provider requests seen: {counts}")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
