<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## API v1

Base URL: `http://127.0.0.1:8000/api/v1`

### Endpoints

```http
POST /articles
POST /articles/{article}/analyze
GET /analyses/{analysis}
```

To create an article, send a JSON body with a valid public HTTP(S) URL:

```json
{
	"url": "https://example.com/article"
}
```

The analysis endpoint is asynchronous. A new analysis returns `202 Accepted` with
its `id`, article, status and algorithm version. If an analysis already exists for
the article, it returns `200` with the existing analysis and does not dispatch a
second job. Use `GET /analyses/{analysis}` to poll its status.

## API Change Log

### 2026-08-27

- Added public URL validation to reject localhost, `.local` hosts and private or reserved IP addresses during article ingestion.
- Added route rate limits: 10 article ingestions, 20 analysis requests and 60 analysis reads per minute per client.
- Added `ArticleResource`, `SourceResource` and `AnalysisResource` to expose a controlled JSON contract instead of serializing complete models.
- Changed newly queued analysis responses from `201 Created` to `202 Accepted`.
- Added a unique database constraint on `analyses.article_id` to protect against duplicate analyses during concurrent requests.
- Added handling for duplicate-key errors from SQLite/MySQL (`23000`) and PostgreSQL (`23505`).
- Removed the analysis error field from the public resource to avoid exposing internal exception details.
- Added global JSON response encoding with `JSON_UNESCAPED_UNICODE`, so characters such as `ñ`, `á`, `é`, `í`, `ó` and `ú` are returned directly instead of as `\\u` sequences.

### Pending decisions

- Authentication and per-user authorization are not enabled yet; `user_id` remains nullable until the project chooses an authentication provider.
- Retry behavior for failed analyses is not enabled yet because it requires deciding whether retries reuse the same analysis or create a new versioned attempt.
- Before deploying the unique constraint to a database with existing data, remove any duplicate rows from `analyses` first.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
