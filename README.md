# COLLABX_PROJECT
# CollabX — Startup Collaboration, Investment & Resource Platform

**Connect Ideas, Capital, and Talent in One Ecosystem.**

CollabX is a PHP and MySQL web application project intended to connect **idea owners, investors, and skilled professionals** in one place. The application includes a multi-page frontend and PHP API endpoints for account access, project discovery, investment workflows, collaboration requests, agreements, messaging, notifications, and wallet/escrow-related views.

> **Project status:** The project ZIP contains frontend pages and PHP API endpoints, and a separate `startup_collab.sql` database dump has now been provided. The presence of a page or endpoint does not by itself prove that a feature works end-to-end; import the database and test each workflow.

## Contents

- [Why CollabX?](#why-collabx)
- [User Roles](#user-roles)
- [Features and Application Areas](#features-and-application-areas)
- [Technology Stack](#technology-stack)
- [Repository Structure](#repository-structure)
- [Requirements](#requirements)
- [Local Setup](#local-setup)
- [Database Configuration](#database-configuration)
- [Important Security Notes](#important-security-notes)
- [Known Limitations](#known-limitations)
- [Future Improvements](#future-improvements)
- [Contributing](#contributing)
- [License](#license)

## Why CollabX?

Good ideas can struggle to move forward when their creators cannot find funding, collaborators, or the right skills. Investors may have difficulty finding promising projects, while skilled professionals may be looking for meaningful work.

CollabX is designed as a shared platform where these groups can discover opportunities and work toward building ventures together.

## User Roles

### 💡 Idea Owners
People who want to present a startup idea or project, describe its needs, and find investors or collaborators.

### 💰 Investors
People who want to browse project opportunities, review proposals, and manage investment-related activity.

### 🛠️ Skilled Talent / Manpower
Professionals who want to discover projects, apply to contribute their skills, and collaborate with idea owners.

### 🛡️ Administrator
An administrative area is included for dashboard statistics, project management, and user verification workflows.

## Features and Application Areas

The repository includes pages and/or PHP endpoints for the following areas. Actual functionality depends on database setup, frontend/API integration, and testing.

| Area | Relevant files | Purpose |
|---|---|---|
| Landing page and navigation | `index.html`, `style.css`, `script.js` | Introduces CollabX and links to the main areas |
| Registration and login | `register.html`, `login.html`, `api/auth/` | Account creation, login, logout, authentication checks, password recovery/reset |
| Idea-owner dashboard | `ideaowner-dashboard.html`, `api/dashboards/`, `api/ideaowner/` | Dashboard information and owner-related requests |
| Investor dashboard | `investor-dashboard.html`, `api/investor/`, `api/investments/` | Project discovery, investment listings, portfolio/status workflows |
| Talent dashboard | `manpower-dashboard.html`, `api/manpower/`, `api/requests/` | Talent dashboard and project application/request workflows |
| Project management | `create-project.html`, `project-details.html`, `api/projects/` | Project creation, listing, approval, and milestone-related endpoints |
| Collaboration requests | `apply-project.html`, `manage-requests.html`, `invite-investor.html`, `api/requests/` | Applying to projects and managing requests |
| Investment and finance | `make-investment.html`, `wallet.html`, `add-revenue.html`, `salary-release.html`, `api/finance/`, `api/investments/` | Investment-related actions, wallet information, transaction and payout-related endpoints |
| Escrow and milestones | `escrow.html`, `manage-milestones.html`, `api/wallet/escrow.php`, `api/projects/milestones.php` | Escrow-related views and milestone workflow endpoints |
| Agreements and negotiation | `agreement-details.html`, `manage-agreement.html`, `negotiations.html`, `api/agreements/` | Agreement management and negotiation endpoints |
| Messaging | `messaging.html`, `api/chat/messages.php` | Message-related interface and API |
| Notifications | `api/notifications/` | Notification retrieval and read-status endpoints |
| Administration | `admin-dashboard.html`, `admin-manage-projects.html`, `admin-verify-users.html`, `api/admin/` | Admin dashboard statistics, project management, and user verification-related endpoints |

**Important:** Wallet, investment, salary, and escrow code in a student project is not equivalent to a production-ready financial service. Do not use it to hold real funds or represent that real payments or legally binding escrow are supported unless those systems have been implemented, independently tested, and appropriately reviewed.

## How CollabX Is Intended to Work

1. **Create an account:** A user registers and selects a role.
2. **Publish a project:** An idea owner describes a project and its funding or talent needs.
3. **Discover opportunities:** Investors and skilled professionals explore available projects.
4. **Connect and negotiate:** Participants use request, messaging, negotiation, or agreement-related workflows where available.
5. **Track project progress:** Project and milestone pages are intended to help participants follow progress and related funding states.

This describes the intended workflow. Exact behavior should be confirmed by running the application.

## Technology Stack

- **Frontend:** HTML, CSS, JavaScript
- **Styling:** Tailwind CSS CDN and custom CSS
- **Backend:** PHP
- **Database:** MySQL / MariaDB
- **Local development environment:** XAMPP or another PHP/MySQL environment

## Repository Structure

```text
collabx/
├── index.html
├── login.html
├── register.html
├── style.css
├── script.js
├── tailwind-config.js
├── assets/
│   └── js/
│       └── app.js
├── config/
│   ├── db.php
│   ├── helpers.php
│   └── notification_helper.php
├── api/
│   ├── auth/
│   ├── admin/
│   ├── agreements/
│   ├── chat/
│   ├── dashboards/
│   ├── finance/
│   ├── ideaowner/
│   ├── investments/
│   ├── investor/
│   ├── manpower/
│   ├── notifications/
│   ├── projects/
│   ├── requests/
│   ├── users/
│   └── wallet/
└── tools/
    └── create_admin.php
```

The repository also contains separate HTML pages for dashboards, projects, investments, agreements, requests, messaging, wallet, and escrow-related workflows.

## Requirements

Install the following before running the application:

- PHP (a version compatible with the PHP code and enabled `mysqli` extension)
- MySQL or MariaDB
- Apache or another PHP-capable web server
- A browser
- XAMPP is a convenient option for local development

## Local Setup

### 1. Download or clone the repository

```bash
git clone YOUR_REPOSITORY_URL
```

Or download the repository ZIP and extract it.

### 2. Place the project in your web root

For XAMPP, copy the `collabx` folder into:

```text
xampp/htdocs/collabx/
```

### 3. Start the services

Open the XAMPP Control Panel and start **Apache** and **MySQL**.

### 4. Create the database

The current `config/db.php` specifies the database name `startup_collab`. Create a database with that name in phpMyAdmin or MySQL, then import the matching schema and seed data.

**The ZIP reviewed for this README did not contain a `.sql` database export/schema.** The application may not run correctly until the required tables and columns are created. Do not assume that creating an empty database is sufficient.

### 5. Configure the database connection

Edit `config/db.php` for your local environment. The current file uses `localhost`, MySQL user `root`, an empty password, and database `startup_collab`. Those values are common in a local XAMPP setup but should not be used as production credentials.

### 6. Open the application

Visit:

```text
http://localhost/collabx/
```

If a page loads but data or forms fail, check the browser console, Apache/PHP logs, database connection, and the corresponding API response.

## Database Configuration

The application uses MySQL through PHP's `mysqli` extension. The connection is currently configured in:

```text
config/db.php
```

The supplied `startup_collab.sql` dump defines the database schema and includes sample records, as well as database objects such as stored procedures, triggers, and views. Keep the SQL dump synchronized with changes to the PHP code and database.

Never upload real passwords, API keys, private user data, or production database credentials to a public repository.

## Important Security Notes

Before making this repository public or deploying it, review the following:

- **Database credentials:** Move environment-specific credentials out of tracked source files.
- **Password verification:** Review the login code and remove any fallback that accepts a stored password as plain text. Store password hashes only and verify them with `password_verify()`.
- **Debug endpoints:** Review and disable or remove development endpoints such as `api/ideaowner/debug_db.php` before deployment.
- **Test endpoints and admin tools:** Restrict or remove test-trigger endpoints and `tools/create_admin.php` from public production access.
- **Authorization:** Enforce role and ownership checks on the server for every sensitive API endpoint. Hiding a button in the browser is not access control.
- **Financial workflows:** Review transaction integrity, balance updates, authorization, audit logs, and concurrency handling. Do not treat a displayed escrow balance as proof that money is held securely.
- **Input validation and output escaping:** Validate all inputs, use prepared statements, and escape output appropriately.
- **Sessions and CSRF:** Review session cookie settings, session ID regeneration, CSRF protection, and logout behavior.
- **Errors and logs:** Avoid returning raw SQL errors, stack traces, or sensitive details to users.
- **HTTPS:** Use HTTPS for any deployment that handles accounts or sensitive information.

These are review recommendations, not a claim that every item is currently vulnerable. A full security audit has not been performed.

## Known Limitations

- The supplied SQL dump includes sample user/project data; review and sanitize it before publishing the repository publicly.
- End-to-end functionality has not been verified by running the application against the imported database.
- External email delivery, payment gateway integration, and production deployment configuration are not documented in the supplied files.
- Investment and escrow-related screens/endpoints should be treated as project/demo functionality until thoroughly tested and reviewed.
- The README's feature list identifies code areas, not a guarantee that every workflow is complete.

## Future Improvements

- Add and maintain a version-controlled database schema and sample seed data.
- Add automated tests for authentication, role permissions, project workflows, and database operations.
- Complete end-to-end testing for project publishing, applications, investment statuses, agreements, milestones, and notifications.
- Add secure email delivery for password recovery.
- Add audit logging and stronger financial transaction controls.
- Improve accessibility, form validation, error handling, and responsive behavior.
- Document deployment, backups, environment variables, and database migrations.

## Contributing

Suggestions and contributions are welcome.

1. Fork the repository.
2. Create a branch for your change.
3. Make and test your changes locally.
4. Open a pull request describing what changed and how it was tested.

## License

No license file was included in the supplied ZIP. Until a license is added, do not assume others have permission to reuse, modify, or redistribute this code. Choose a license that matches your intentions and add a `LICENSE` file.

---

**CollabX** — Connecting ideas, capital, and talent to build opportunities together.
