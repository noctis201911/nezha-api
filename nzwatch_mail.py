#!/usr/bin/env python3
# 哪吒看门狗邮件发送器 — 由 nzwatch.sh 调用; SMTP凭证从环境变量读(nzwatch.sh 运行时从 .env 注入, 不硬编码)
import os, sys, ssl, smtplib
from email.mime.text import MIMEText
from email.utils import formatdate
try:
    body = os.environ.get("NZ_BODY", "(empty)")
    msg = MIMEText(body, "plain", "utf-8")
    msg["Subject"] = os.environ.get("NZ_SUBJ", "Nezha watchdog alert")
    msg["From"]    = os.environ["NZ_FROM"]
    msg["To"]      = os.environ["NZ_TO"]
    msg["Date"]    = formatdate(localtime=True)
    host = os.environ["NZ_HOST"]; port = int(os.environ.get("NZ_PORT", "587"))
    s = smtplib.SMTP(host, port, timeout=25)
    s.ehlo()
    s.starttls(context=ssl.create_default_context())
    s.login(os.environ["NZ_USER"], os.environ["NZ_PASS"])
    s.sendmail(os.environ["NZ_FROM"], [os.environ["NZ_TO"]], msg.as_string())
    s.quit()
    print("nzwatch_mail: sent to " + os.environ["NZ_TO"])
except Exception as e:
    sys.stderr.write("nzwatch_mail: FAILED %s\n" % e)
    sys.exit(1)
