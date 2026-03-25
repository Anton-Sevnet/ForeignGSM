# GoIP SMS Relay (ForeignGSM)

Принимает входящие SMS от шлюзов GoIP по **UDP 44444** (режим SMS Server) и пересылает их через **HTTP GET** `send.html` на выбранный шлюз (тот же или второй).

- **PHP 5.6+** CLI, расширения: `sockets`, `curl`.
- Развёртывание на хосте Asterisk: `/opt/goip-relay/`, конфиг `/etc/goip-relay/config.json`.

## Быстрый деплой на Debian / ATS

См. [DEPLOY.md](DEPLOY.md). На сервер копируйте содержимое этой папки в `/opt/goip-relay/`.

## Файлы

| Файл | Назначение |
|------|------------|
| `relay.php` | Демон |
| `lib/GoIP/*.php` | UDP-протокол (MIT, upstream: cjzamora/goip-sms-gateway) |
| `config.json.example` | Два шлюза |
| `config.loopback.example.json` | Один шлюз (тест) |
| `init.d/goip-relay` | SysV init (Debian 8) |

## Лицензия

См. [LICENSE-GOIP.txt](LICENSE-GOIP.txt) для библиотеки GoIP; остальное — в рамках проекта ForeignGSM.
