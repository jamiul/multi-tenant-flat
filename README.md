# Ticket Sync Application
Laravel-based application that integrates with a third-party enterprise service Zapier Webhook to synchronize data between a local database and the external platform. The application is supported by user authentication via a social provider Google.

# Setup instructions

## Prerequisites
- Docker and Docker Compose
- Node.js and NPM
- Composer
- Google OAuth credentials
- Zapier Webhook URL

## Tech Stack
- Laravel 12
- Docker (Docker Compose)
- MySQL 8.0
- PHP 8.4
- Node.js 22
- NPM
- Google OAuth
- Zapier Webhook
- Pest (Testing)

## Environment Setup
1. Copy the example environment file:
   ```bash
   cp .env.example .env
   ```
2. Generate application key:
   ```bash
   php artisan key:generate
   ```

## Configure OAuth (Google)
1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Navigate to "APIs & Services" > "Credentials"
4. Click "Create Credentials" > "OAuth client ID"
5. Select "Web application" as the application type
6. Add authorized redirect URIs:
   - `http://localhost/auth/google/callback`
7. Add authorized JavaScript origins:
   - `http://localhost`
8. Add the following to your `.env` file:
   ```
   GOOGLE_CLIENT_ID=your_google_client_id
   GOOGLE_CLIENT_SECRET=your_google_client_secret
   GOOGLE_REDIRECT_URI=http://localhost/auth/google/callback
   ```

## Connect to Zapier Webhook
1. Log in to your Zapier account
2. Create a new Zap
3. Choose a trigger for your webhook
4. Copy the webhook URL
5. Add the webhook URL to your `.env` file:
   ```
   ZAPIER_WEBHOOK_URL=your_webhook_url_here
   ```

## Build and Run the Application

### Using Docker
```bash
docker/build.sh
docker/run.sh
```

### Manual Setup
1. Open Bash
   ```bash
   docker/bash.sh
   ```
2. Install PHP dependencies:
   ```bash
   composer install
   ```
3. Install Node.js dependencies:
   ```bash
   npm install
   ```
4. Run database migrations:
   ```bash
   php artisan migrate
   ```
5. In a new terminal, start Vite:
   ```bash
   npm run dev
   ```
6. Test the application:
   ```bash
   php artisan test
   ```

## Testing the Sync Functionality
1. Access the application in your browser:
   ```
   http://localhost/login
   ```
2. Click "Sign in with Google" and complete the OAuth flow
3. Navigate to the sync section
4. Create or update a ticket in the application
5. Verify the data appears in your Zapier webhook
6. Check your third-party service to confirm the data was synced

## Screenshots

### 1. Login Page with Google OAuth
![Login Page](src/public/images/oauth-login.png)

### 2. Create New Ticket
![Create Ticket](src/public/images/create-new-ticket.png)

### 3. Ticket Form
![Fill Up Form](src/public/images/fill-up-form.png)

### 4. Ticket List
![Ticket List](src/public/images/ticket-list.png)

### 5. Dashboard
![Dashboard](src/public/images/dashboard-ticket-list.png)

### 6. Sync Functionality
![Sync Button](src/public/images/sync-all-button.png)
### 7. Show Sync Data on Zapier Webhook Dashboard
![Sync Success](src/public/images/data-sync-success.png)

## Time Spent on Project
- Project Setup: 4 hours
  - Initial Laravel setup
  - Docker configuration
  - Environment setup
- Authentication: 6 hours
  - Google OAuth integration
  - User model and migrations
- Core Functionality: 10 hours
  - Ticket model and relationships
  - Sync service implementation
  - Error handling
- Testing & Debugging: 5 hours
  - Unit tests
  - Integration testing
  - Bug fixes
- Documentation: 2 hours
  - README updates
  - Code comments

**Total Estimated Time: 27 hours**

## Troubleshooting
- If you encounter OAuth errors, double-check your Google Cloud Console settings
- Ensure all environment variables are properly set in your `.env` file
- Check the Laravel logs for detailed error messages:
  ```bash
  tail -f storage/logs/laravel.log
  ```
