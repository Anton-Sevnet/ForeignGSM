# Развёртывание goip-relay на ATS (Debian 8)

## 1. Скопировать файлы на сервер

С машины разработки (или из репозитория):

```sh
scp -r goip-relay root@<ats_ip>:/tmp/
ssh root@<ats_ip>
mkdir -p /opt/goip-relay
cp -a /tmp/goip-relay/relay.php /tmp/goip-relay/run-forever.sh /tmp/goip-relay/lib /opt/goip-relay/
chmod 755 /opt/goip-relay/relay.php /opt/goip-relay/run-forever.sh
```

## 2. Конфиг

```sh
mkdir -p /etc/goip-relay
cp /tmp/goip-relay/config.loopback.example.json /etc/goip-relay/config.json
chmod 640 /etc/goip-relay/config.json
chown root:root /etc/goip-relay/config.json
```

Отредактируйте `/etc/goip-relay/config.json`:

- `sms_client_id` / `sms_password` — как на странице **Configurations → SMS** на GoIP (SMS Server).
- `http_base`, `http_user`, `http_pass` — веб-доступ к шлюзу (как в браузере).
- `destination` — номер получателя пересланного SMS (удобный ввод: `+…`, `00…`, для РФ также `8…` / `9…` / `11 цифр с 7`); relay приводит к **E.164 с `+`** для `send.html`.
- Опционально **`default_calling_code`** (корень JSON, только цифры, без `+`) — если номер национальный (6–12 цифр) и не попал под явные правила (например NANP без ведущей `1`).
- Для двух шлюзов используйте `config.json.example` как образец и заполните `gw2`.

## 3. Лог

```sh
touch /var/log/goip-relay.log
chmod 640 /var/log/goip-relay.log
chown root:root /var/log/goip-relay.log
```

Ротация (пример `/etc/logrotate.d/goip-relay`): еженедельно, 8 архивов, `compress`, `copytruncate` — без перезапуска демона.

## 4. Firewall / MikroTik

Разрешите **UDP 44444** с IP GoIP на IP ATS (и обратно не требуется для ответов stateful).

## 5. Настройка GoIP (веб-морда)

На каждом шлюзе, с которого принимаете SMS:

1. **Configurations → SMS**
2. **SMS Server:** Enable
3. **SMS Server IP:** IP хоста ATS
4. **SMS Server Port:** `44444`
5. **SMS Client ID** и **Password** — те же строки, что в `config.json` → `sms_client_id` / `sms_password` для соответствующего `gw*`.
6. Выберите канал **CH1** (или нужный) и сохраните.

## 6. Init-скрипт

```sh
cp /tmp/goip-relay/init.d/goip-relay /etc/init.d/goip-relay
chmod 755 /etc/init.d/goip-relay
update-rc.d goip-relay defaults
service goip-relay start
service goip-relay status
```

Проверка лога:

```sh
tail -f /var/log/goip-relay.log
```

Должны появляться строки `keepalive_ack` примерно каждые 30 с после регистрации GoIP.

## 7. Тест (один шлюз, loopback)

1. В конфиге маршрут: `out_gw` = `gw1`, `out_slot` = 1, `destination` = ваш тестовый номер.
2. Отправьте SMS на номер SIM1 на GoIP.
3. В логе ожидается строка `forward ... http=200` (или ответ шлюза в `resp=`).
4. На телефоне — входящее с префиксом `From:...`.

## 8. Второй шлюз

Добавьте блок `gw2` в `gateways`, маршрут с `out_gw`: `gw2`, нужным `out_slot` и `destination`. Код менять не нужно.

## Устранение неполадок

| Симптом | Действие |
|---------|----------|
| Нет `keepalive_ack` | UDP 44444 не доходит; проверить firewall и IP ATS на GoIP |
| `receive_unknown_client` | Неверный `sms_client_id` в JSON |
| `receive_bad_password` | Неверный `sms_password` |
| `receive_no_route` | Нет маршрута для `match_gw` / `match_slot`; проверьте слот (поля `port`/`line` в пакете) |
| HTTP не 200 | Логин/пароль веб-морды, канал `l`, доступность `http_base` с ATS |

## Ручной запуск (отладка)

```sh
php /opt/goip-relay/relay.php --config=/etc/goip-relay/config.json
```

Остановка: Ctrl+C или `service goip-relay stop`.
