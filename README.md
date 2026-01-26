# SMS Panel

Web-based SMS management panel for OpenVox and GoIP GSM gateways with multi-user support.

![PHP](https://img.shields.io/badge/PHP-7.4+-blue)
![MySQL](https://img.shields.io/badge/MySQL-5.7+-orange)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5.3-purple)
![License](https://img.shields.io/badge/License-MIT-green)

## Features

### 🔐 User Authentication & Authorization
- **User Roles** - Admin and User roles with different permissions
- **Port-based Access** - Assign specific ports to users (send/receive)
- **Data Isolation** - Contacts, templates, groups isolated per user
- **Session Management** - Secure login/logout system

### 📤 SMS Management
- **Send SMS** - Single message to one or multiple recipients
- **Bulk Campaigns** - Mass SMS with progress tracking and pause/resume
- **Templates** - Reusable message templates with variable substitution
- **Personalization** - Use `{name}` and other variables in messages

### 📥 Inbox & Outbox
- **Incoming Messages** - View received SMS with sender info
- **Sent Messages** - Track all outgoing messages with delivery status
- **Search & Filter** - Find messages by phone, content, date
- **User Filtering** - Users see only messages from their allowed ports

### 🔌 Gateway Support
- **OpenVox** - VS-GW1600, VS-GW2120 and other GSM gateways
- **GoIP** - GoIP 1/4/8/16/32 GSM gateways
- **Multiple Gateways** - Connect unlimited gateways simultaneously
- **Auto-failover** - Priority-based gateway selection

### 📱 Port Management
- **Per-gateway ports** - Manage ports for each gateway separately
- **SIM Tracking** - Associate phone numbers with ports
- **Usage Statistics** - Track messages sent per port
- **Port Rotation** - Random, linear, or specific port selection

### 👥 Contacts & Groups
- **Contact Database** - Store names and phone numbers (per user)
- **Groups** - Organize contacts into groups (per user)
- **Import** - Bulk import from CSV files
- **Quick Send** - Send SMS directly from contact list

### 🌍 Localization
- **Multi-language** - English and Russian interface
- **Easy to extend** - Add new languages via PHP files

## Requirements

| Component | Minimum Version |
|-----------|-----------------|
| PHP | 7.4+ |
| MySQL | 5.7+ (or MariaDB 10.2+) |
| Web Server | Apache 2.4+ / Nginx 1.18+ |

### PHP Extensions
- PDO + PDO_MySQL
- cURL
- JSON
- mbstring (recommended)

## Installation

### Option 1: Web Installer (Recommended)

1. **Download and extract** to your web root:
```bash
cd /var/www/html
tar -xzf sms-panel.tar.gz
chown -R www-data:www-data sms-panel
```

2. **Open installer** in browser:
```
http://your-server/sms-panel/install.php
```

3. **Follow the wizard**:
   - Step 1: System requirements check
   - Step 2: Database configuration
   - Step 3: First gateway setup (optional)
   - Step 4: Complete!

4. **Login** with default credentials:
   - Username: `admin`
   - Password: `admin123`

5. **Delete installer** (important!):
```bash
rm /var/www/html/sms-panel/install.php
```

6. **Change admin password** in Users management

### Option 2: Manual Installation

1. **Create database**:
```sql
CREATE DATABASE sms_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sms_panel'@'localhost' IDENTIFIED BY 'your_password';
GRANT ALL PRIVILEGES ON sms_panel.* TO 'sms_panel'@'localhost';
FLUSH PRIVILEGES;
```

2. **Import schema**:
```bash
mysql -u root -p sms_panel < schema.sql
```

3. **Configure** `includes/config.php`:
```php
<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'sms_panel');
define('DB_PASS', 'your_password');
define('DB_NAME', 'sms_panel');
define('SPAM_INTERVAL', 60);
define('APP_NAME', 'SMS Panel');
define('APP_VERSION', '1.2');
define('TIMEZONE', 'Europe/Moscow');
date_default_timezone_set(TIMEZONE);
```

4. **Set permissions**:
```bash
chmod 755 logs/ exports/
chown -R www-data:www-data logs/ exports/
```

5. **Login** with `admin` / `admin123`

### Upgrading from v1.1

Run the migration script:
```bash
mysql -u root -p sms_panel < migrations/001_add_user_auth.sql
```

Or execute SQL manually:
```sql
-- Add user tables
source migrations/001_add_user_auth.sql;
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name sms.example.com;
    root /var/www/html/sms-panel;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(git|gitignore) {
        deny all;
    }
}
```

## User Management

### User Roles

| Role | Permissions |
|------|-------------|
| **Admin** | Full access to all features, all data, user management |
| **User** | Access only to assigned ports and own data |

### Creating Users (Admin only)

1. Go to **Users** in admin menu
2. Click **Add User**
3. Fill in user details:
   - Username and password
   - Display name and email
   - Role (Admin/User)
4. **Assign port permissions**:
   - Select which ports the user can access
   - Set Send and/or Receive permissions per port
5. Click **Save**

### Data Isolation

| Data Type | Admin View | User View |
|-----------|------------|-----------|
| Dashboard | All ports statistics | Only allowed ports |
| Inbox | All messages | Messages from allowed ports |
| Outbox | All messages | Messages from allowed ports |
| Contacts | All contacts | Only own contacts |
| Groups | All groups | Only own groups |
| Templates | All templates | Only own templates |
| Campaigns | All campaigns | Only own campaigns |

## Configuration

### Adding a Gateway (Admin only)

1. Navigate to **Gateways** (admin menu)
2. Click **Add Gateway**
3. Fill in the form:

| Field | Description | Example |
|-------|-------------|---------|
| Name | Descriptive name | "Office Gateway" |
| Type | Gateway type | OpenVox / GoIP |
| Host | IP address | 192.168.1.100 |
| Port | HTTP port | 80 |
| Username | API username | admin |
| Password | API password | ******* |
| Channels | Number of SIM slots | 8 |
| Priority | Higher = preferred | 10 |

4. Click **Save**
5. Go to **Gateway Ports** and click **Generate Ports**

### OpenVox Port Format

OpenVox uses modules with **4 ports each**:

| Port # | Format | Module |
|--------|--------|--------|
| 1 | gsm-1.1 | Module 1 |
| 2 | gsm-1.2 | Module 1 |
| 3 | gsm-1.3 | Module 1 |
| 4 | gsm-1.4 | Module 1 |
| 5 | gsm-2.1 | Module 2 |
| ... | ... | ... |

### GoIP Port Format

GoIP uses simple line numbers: `Line 1`, `Line 2`, etc.

## Usage

### Sending SMS

1. Go to **Send SMS**
2. Enter recipients:
   - Type phone numbers (one per line)
   - Or select from contacts/groups
3. Write message or select template
4. Choose sending options:
   - **Gateway**: Specific or automatic
   - **Port Mode**: Random / Linear / Specific
5. Click **Send**

### Creating a Campaign

1. Go to **Bulk SMS** → **New Campaign**
2. Add recipients:
   - Paste numbers (one per line, format: `phone` or `phone,name`)
   - Upload CSV file
   - Select contact group
3. Write message (use `{name}` for personalization)
4. Configure:
   - **Gateway**: All or specific
   - **Port Mode**: How to rotate ports
   - **Delay**: Milliseconds between messages
5. **Save** (creates draft)
6. **Start** when ready

### Templates with Variables

Create templates with placeholders:
```
Hello {name}! Your code is {code}. Valid for 5 minutes.
```

## API

### Receiving SMS (Webhook)

Configure your gateway to POST incoming SMS to:
```
http://your-server/sms-panel/api/receive.php
```

**Parameters** (form-data or JSON):
| Parameter | Description |
|-----------|-------------|
| from | Sender phone number |
| to | Recipient number (gateway SIM) |
| message | Message text |
| port | Port number (optional) |

**Example**:
```bash
curl -X POST http://your-server/sms-panel/api/receive.php \
  -d "from=+79001234567" \
  -d "to=+79009876543" \
  -d "message=Hello World"
```

**Response**:
```json
{"success": true, "message_id": 123}
```

## File Structure

```
sms-panel/
├── ajax/                   # AJAX handlers
├── api/
│   └── receive.php         # Incoming SMS webhook
├── includes/
│   ├── config.php          # Configuration
│   ├── database.php        # Database class
│   ├── auth.php            # Authentication
│   ├── sms.php             # SMS sending logic
│   ├── campaign.php        # Campaign management
│   ├── contacts.php        # Contact management
│   ├── templates.php       # Template management
│   ├── lang.php            # Language loader
│   └── lang/
│       ├── en.php          # English
│       └── ru.php          # Russian
├── migrations/
│   └── 001_add_user_auth.sql  # v1.2 migration
├── templates/
│   └── layout.php          # HTML layout
├── logs/                   # SMS logs
├── exports/                # Exported files
├── index.php               # Dashboard
├── login.php               # Login page
├── logout.php              # Logout handler
├── users.php               # User management (admin)
├── send.php                # Send SMS
├── inbox.php               # Incoming messages
├── outbox.php              # Sent messages
├── bulk.php                # Bulk campaigns
├── contacts.php            # Contact list
├── groups.php              # Contact groups
├── templates.php           # Message templates
├── gateways.php            # Gateway management (admin)
├── ports.php               # Port management (admin)
├── settings.php            # System settings (admin)
├── install.php             # Installation wizard
├── schema.sql              # Database schema
└── README.md
```

## Troubleshooting

### Cannot login

- ✅ Default credentials: `admin` / `admin123`
- ✅ Check if users table exists: `SELECT * FROM users;`
- ✅ Reset password via SQL:
```sql
UPDATE users SET password = '$2y$10$N9qo8uLOickgx2ZMRZoMyeNw/O3z5BKQXB1KdJH5LrM3F5z2DlWHW' WHERE username = 'admin';
```

### User sees no data

- ✅ Check user has port permissions assigned
- ✅ Verify ports have `can_send` or `can_receive` enabled
- ✅ Admin can see all data; check user role

### Cannot connect to gateway

- ✅ Verify gateway IP is reachable: `ping 192.168.1.100`
- ✅ Check HTTP port is open
- ✅ Verify credentials in gateway web interface

### Messages not sending

- ✅ Check `logs/sms_YYYY-MM-DD.log` for errors
- ✅ Verify port has active SIM card
- ✅ Check SIM balance and network signal

## Security Recommendations

1. **Delete `install.php`** after installation
2. **Change default admin password** immediately
3. **Use HTTPS** in production
4. **Restrict access** by IP if possible
5. **Strong passwords** for database and gateways
6. **Regular backups** of database
7. **Keep software updated** (PHP, MySQL, web server)
8. **Set proper permissions**:
   ```bash
   find . -type f -exec chmod 644 {} \;
   find . -type d -exec chmod 755 {} \;
   chmod 755 logs/ exports/
   ```

## License

MIT License - see [LICENSE](LICENSE) file for details.

## Changelog

### v1.2 (2025-01)
- **User authentication system**
  - Login/logout functionality
  - Admin and User roles
  - Session management
- **Port-based permissions**
  - Assign specific ports to users
  - Separate send/receive permissions
- **Data isolation**
  - Contacts per user
  - Groups per user
  - Templates per user
  - Campaigns per user
- **Dashboard filtering** by allowed ports
- **Inbox/Outbox filtering** by allowed ports
- **Migration script** for upgrading from v1.1

### v1.1 (2025-01)
- Multiple gateway support
- Gateway priority routing
- Per-gateway port management
- Bulk campaign gateway selection
- Installation wizard

### v1.0 (2025-01)
- Initial release
- OpenVox and GoIP support
- Send/receive SMS
- Bulk campaigns
- Contact management
- Message templates
- Multi-language (EN/RU)
