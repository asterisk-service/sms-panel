# Changelog

All notable changes to SMS Panel will be documented in this file.

## [1.2] - 2025-01-26

### Added
- **User Authentication System**
  - Login/logout functionality with session management
  - Admin and User roles
  - Secure password hashing with bcrypt
  - Last login tracking

- **Port-based Permissions**
  - Assign specific gateway ports to users
  - Separate send/receive permissions per port
  - User management page for administrators

- **Data Isolation per User**
  - Contacts isolated by user
  - Contact groups isolated by user
  - Message templates isolated by user
  - Bulk campaigns isolated by user

- **Filtered Views**
  - Dashboard shows only allowed ports statistics
  - Inbox filtered by user's receive-allowed ports
  - Outbox filtered by user's send-allowed ports

- **Installation Improvements**
  - Admin account creation step in installer
  - Better error handling during setup

- **Migration Support**
  - SQL migration script for upgrading from v1.1
  - Documentation for database updates

### Changed
- Sidebar shows user info and role
- Admin-only pages: Users, Gateways, Ports, Settings
- Version updated to 1.2

### Fixed
- getCurrentLanguage function alias for login page
- Password hash for default admin user

## [1.1] - 2025-01-17

### Added
- Multiple gateway support
- Gateway priority-based routing
- Per-gateway port management
- OpenVox 4-port module format (gsm-X.Y)
- GoIP gateway support with simple line format
- Bulk campaign gateway selection
- Web installation wizard
- Comprehensive documentation

### Changed
- Ports restructured per-gateway
- Improved sidebar navigation

## [1.0] - 2025-01-17

### Added
- Initial release
- OpenVox GSM gateway support
- Send/receive SMS functionality
- Bulk SMS campaigns with progress tracking
- Contact management with groups
- Message templates with variables
- CSV import/export
- Multi-language support (EN/RU)
- Real-time dashboard statistics
- Message search and filtering
- Anti-spam protection
