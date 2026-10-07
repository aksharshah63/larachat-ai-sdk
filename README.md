# Larachat AI SDK

This project is a Laravel application integrating the Laravel AI SDK with Livewire. It serves as a starter kit or implementation for AI-powered chat and related features.

## Prerequisites

- PHP >= 8.3
- Composer
- Node.js and NPM
- A database supported by Laravel (e.g., MySQL, SQLite, PostgreSQL)

## Setup and Installation

1. **Clone the repository** (if you haven't already):
   ```bash
   git clone <your-repository-url>
   cd larachat-ai-sdk-main
   ```

2. **Run the setup script**:
   This project includes a convenient composer script to handle the initial setup. Run:
   ```bash
   composer setup
   ```
   *This command will install PHP dependencies, create your `.env` file, generate an application key, run database migrations, install NPM dependencies, and build your frontend assets.*

3. **Configure Environment Variables**:
   Open the newly created `.env` file and configure your database settings, AI API keys, and any other necessary environment variables.

## Running the Application Locally

You can start the local development environment using the provided composer script:

```bash
composer dev
```
*This command runs the Laravel development server, the queue listener, the application log tailer, and the Vite development server concurrently.*

Alternatively, you can run the standard commands individually:
- `php artisan serve` (Starts the PHP server)
- `npm run dev` (Starts the Vite frontend server)

## Testing and Linting

- **Run Tests**: `composer test`
- **Lint Code**: `composer lint`
- **Check Linting**: `composer lint:check`

## Technologies Used

- [Laravel](https://laravel.com/) (v13.x)
- [Laravel Livewire](https://livewire.laravel.com/) (v4.x)
- [Laravel AI SDK](https://github.com/laravel/ai)

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
