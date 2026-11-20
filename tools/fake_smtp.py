#!/usr/bin/env python3
"""Attrappe eines SMTP-Servers für tools/test.sh (ohne TLS, nimmt jede Anmeldung an).

    python3 tools/fake_smtp.py <port> <ausgabedatei>

Jede angenommene Nachricht wird (base64-Teil dekodiert) an die Ausgabedatei angehängt. Ein Empfänger, der mit
«reject» beginnt, wird bei RCPT TO mit 550 abgelehnt.
"""
import base64, socket, sys

port, out = int(sys.argv[1]), sys.argv[2]
srv = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
srv.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
srv.bind(('127.0.0.1', port))
srv.listen(5)
while True:
    conn, _ = srv.accept()
    f = conn.makefile('rwb')
    def say(line):
        f.write(line.encode() + b'\r\n'); f.flush()
    say('220 fake ESMTP')
    try:
        while True:
            line = f.readline()
            if not line:
                break
            cmd = line.decode(errors='replace').strip()
            up = cmd.upper()
            if up.startswith('EHLO'):
                say('250 fake'); continue
            if up == 'AUTH LOGIN':
                say('334 VXNlcm5hbWU6'); f.readline(); say('334 UGFzc3dvcmQ6'); f.readline(); say('235 ok'); continue
            if up.startswith('MAIL FROM'):
                say('250 ok'); continue
            if up.startswith('RCPT TO'):
                say('550 no such user' if '<reject' in cmd.lower() else '250 ok'); continue
            if up == 'DATA':
                say('354 go')
                data = []
                while True:
                    l = f.readline()
                    if l in (b'.\r\n', b'.\n', b''):
                        break
                    data.append(l)
                raw = b''.join(data).decode(errors='replace')
                head, _, body = raw.partition('\r\n\r\n')
                try:
                    body = base64.b64decode(''.join(body.split())).decode()
                except Exception:
                    pass
                with open(out, 'a') as o:
                    o.write(head + '\n\n' + body + '\n----\n')
                say('250 queued'); continue
            if up == 'QUIT':
                say('221 bye'); break
            say('250 ok')
    except Exception:
        pass
    finally:
        try:
            conn.close()
        except Exception:
            pass
