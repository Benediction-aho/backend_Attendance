#!/usr/bin/env python3
"""REL-03 re-test: link-like sign-up payloads (unicode, homoglyphs, punycode, spaces, quoted e-mail local parts).
LOCAL ONLY. Usage: python3 -I release_retest_signup.py http://localhost:8282/api
Prints status per payload; accepted payloads must then be checked in the log-mailer output."""
import json, subprocess, sys, random
B = sys.argv[1]
base = {"email": None, "first_name": "Ama", "last_name": "Mensah", "employee_type": "employee", "job_title": "Accountant"}
cases = [
  ("fn fullwidth dot", {"first_name": "evil．example"}),
  ("fn ideographic dot", {"first_name": "evil。example"}),
  ("fn one-dot-leader", {"first_name": "evil․example"}),
  ("fn small full stop", {"first_name": "evil﹒example"}),
  ("fn dot + space", {"first_name": "evil. example"}),
  ("fn space dot", {"first_name": "evil .example"}),
  ("fn punycode no dot", {"first_name": "xn--evil-9ka"}),
  ("fn combining dot", {"first_name": "eviḷcom"}),
  ("fn nbsp", {"first_name": "Click here"}),
  ("fn zero-width", {"first_name": "evil​.​example"}),
  ("fn RTL override", {"first_name": "Ama‮moc"}),
  ("fn arabic dot-like letter", {"first_name": "evil۔com"}),
  ("fn cyrillic homoglyph", {"first_name": "рaypal"}),
  ("fn newline", {"first_name": "Ama\nVisit"}),
  ("jt urlish slash", {"job_title": "evil/com/login"}),
  ("jt hash", {"job_title": "sign in at evil#com"}),
  ("jt fullwidth url", {"job_title": "https：／／evil．example"}),
  ("jt dot space TLD", {"job_title": "Visit evil. com now"}),
  ("jt comma domain", {"job_title": "evil,com"}),
  ("jt digits ip", {"job_title": "Go to 203 0 113 5"}),
  ("jt phone", {"job_title": "Call +233 20 000 0000 to verify"}),
  ("jt long phishing words", {"job_title": "URGENT your password expires today reply with your code"}),
  ("email quoted url", {"email": '"https://evil.example/login"@relsec.test'}),
  ("email quoted spaces", {"email": '"Your account is locked visit evil example"@relsec.test'}),
  ("email domain lookalike", {"email": "it-support@assistanceghana-login.com"}),
  ("email idn", {"email": "a@xn--assistancegana-9kb.com"}),
]
for label, over in cases:
    d = dict(base); d.update(over)
    if d["email"] is None:
        d["email"] = f"su{random.randint(1, 10**9)}@relsec.test"
    ip = f"127.40.{random.randint(1,250)}.{random.randint(1,250)}"
    out = subprocess.run(["curl", "-s", "-w", " %{http_code}", "--interface", ip, "-X", "POST", B + "/auth/register",
        "-H", "Accept: application/json", "-H", "Content-Type: application/json", "-d", json.dumps(d)],
        capture_output=True, text=True).stdout
    body, code = out.rsplit(" ", 1)
    print(f"{label:28} {code} {body[:120] if code != '202' else ''}")
