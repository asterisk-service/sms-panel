# OpenVox SMS Panel

Веб-интерфейс для управления SMS через OpenVox GSM Gateway.

## Возможности

- ✅ **Входящие SMS** - прием и хранение входящих сообщений
- ✅ **Отправка SMS** - отправка на один или несколько номеров
- ✅ **Шаблоны SMS** - создание шаблонов с переменными {name}, {code} и т.д.
- ✅ **Анти-спам** - защита от повторной отправки на тот же номер (60 сек)
- ✅ **Телефонная книга** - контакты с группами, импорт/экспорт CSV/vCard
- ✅ **Группы контактов** - массовая отправка по группам
- ✅ **Массовая рассылка (Bulk SMS)** - рассылка с выбором портов и задержками
- ✅ **Управление портами** - настройка SIM-карт и портов шлюза
- ✅ **Мультиязычность** - русский и английский интерфейс

## Массовая рассылка (Bulk SMS)

Мощный инструмент для массовой отправки SMS:

- **Импорт номеров**: вставка текста, CSV файл или выбор из группы контактов
- **Персонализация**: использование {name} для подстановки имени получателя
- **Режимы выбора порта**:
  - Случайный (Random) - случайный выбор порта для каждого сообщения
  - Линейный (Round Robin) - последовательный перебор портов
  - Конкретный порт - использование одного порта
- **Настройка задержки**: от 100 мс до 60 сек между сообщениями
- **Статусы доставки**: отслеживание sent/failed/delivered от шлюза
- **Управление рассылкой**: пауза, продолжение, отмена

## Требования

- PHP 7.4+ с расширениями: PDO, PDO_MySQL, cURL, mbstring
- MySQL 5.7+ или MariaDB 10.3+
- Web-сервер Apache/Nginx
- OpenVox GSM Gateway с HTTP API

## Установка

### 1. Скопируйте файлы

```bash
# Скопируйте папку sms-panel в директорию веб-сервера
cp -r sms-panel /var/www/html/
cd /var/www/html/sms-panel

# Создайте директории для логов и экспорта
mkdir -p logs exports
chmod 777 logs exports
```

### 2. Настройте конфигурацию

Отредактируйте файл `includes/config.php`:

```php
// MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'sms_panel');
define('DB_USER', 'root');
define('DB_PASS', 'your_password');

// OpenVox Gateway
define('GW_HOST', '192.168.210.228');  // IP вашего шлюза
define('GW_PORT', '80');
define('GW_USER', 'alexcr');           // Логин
define('GW_PASS', 'mahapharata');      // Пароль

// Anti-spam
define('SPAM_INTERVAL', 60);           // Секунд между SMS на один номер
```

### 3. Откройте в браузере

```
http://your-server/sms-panel/
```

База данных и таблицы создадутся автоматически при первом запуске.

### 4. Настройте прием входящих SMS

В веб-интерфейсе OpenVox Gateway:

1. Перейдите в **SMS → SMS Settings → HTTP to SMS**
2. Включите **"SMS to HTTP"**
3. Установите URL:

```
http://YOUR_SERVER/sms-panel/api/receive.php?phonenumber=${phonenumber}&port=${port}&portname=${portname}&message=${message}&time=${time}&imsi=${imsi}
```

## Структура файлов

```
sms-panel/
├── includes/
│   ├── config.php      # Конфигурация
│   ├── database.php    # PDO обертка
│   ├── schema.php      # Создание таблиц
│   ├── sms.php         # Логика отправки/приема SMS
│   ├── contacts.php    # Работа с контактами
│   ├── templates.php   # Шаблоны SMS
│   ├── campaign.php    # Массовая рассылка
│   ├── lang.php        # Система локализации
│   └── lang/
│       ├── ru.php      # Русский язык
│       └── en.php      # Английский язык
├── templates/
│   └── layout.php      # Базовый шаблон HTML
├── api/
│   └── receive.php     # Webhook для входящих SMS и статусов доставки
├── ajax/
│   ├── search_contacts.php    # Поиск контактов
│   ├── get_group_phones.php   # Телефоны группы
│   └── campaign_send.php      # Отправка сообщений рассылки
├── logs/               # Логи входящих SMS
├── exports/            # Экспорт контактов
├── index.php           # Dashboard
├── inbox.php           # Входящие SMS
├── outbox.php          # Исходящие SMS
├── send.php            # Отправка SMS
├── bulk.php            # Массовая рассылка
├── templates.php       # Шаблоны
├── contacts.php        # Телефонная книга
├── groups.php          # Группы контактов
├── ports.php           # Управление портами
└── settings.php        # Настройки
```

## База данных

Таблицы:

- `inbox` - входящие SMS
- `outbox` - исходящие SMS
- `templates` - шаблоны
- `contacts` - контакты
- `contact_groups` - группы
- `spam_log` - лог анти-спама
- `settings` - настройки

## API OpenVox

Отправка SMS:
```
GET http://192.168.210.228/sendsms?username=user&password=pass&phonenumber=79001234567&message=Test
```

## Формат импорта контактов

### CSV (разделитель - точка с запятой):
```
Имя;Телефон;Компания;Email;Заметки;ID группы
Иван Иванов;79001234567;ООО Рога и Копыта;ivan@example.com;VIP клиент;1
```

### vCard (.vcf):
```
BEGIN:VCARD
VERSION:3.0
FN:Иван Иванов
TEL:+79001234567
EMAIL:ivan@example.com
ORG:ООО Рога и Копыта
END:VCARD
```

## Безопасность

⚠️ **Важно:** В production добавьте авторизацию!

Рекомендации:
- Добавьте HTTP Basic Auth на уровне веб-сервера
- Ограничьте доступ по IP
- Используйте HTTPS
- Измените пароли по умолчанию

## Автор

Создано с помощью Claude AI для OpenVox GSM Gateway.
