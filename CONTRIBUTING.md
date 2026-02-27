# Contributing to Bulk Plugin Installer

Thank you for your interest in contributing! This document provides guidelines and recommendations for contributing to the project.

## Branch Protection Recommendations

We recommend enabling the following branch protection rules on `main`:

- **Require pull request reviews** before merging (at least 1 approval)
- **Require status checks to pass** before merging:
  - `phpcs` (lint workflow)
  - `phpstan` (lint workflow)
  - `test` (test workflow — at least PHP 8.3 / WP latest)
  - `composer-audit` (security workflow)
- **Require branches to be up to date** before merging
- **Require conversation resolution** before merging
- **Enforce conventional commits** in PR titles (use a CI check or PR title linter)
- **Do not allow force pushes** to `main`
- **Do not allow deletions** of `main`

## Development Setup

1. Clone the repository
2. Install dependencies:
   ```bash
   composer install
   ```
3. Run the test suite:
   ```bash
   vendor/bin/phpunit
   ```

## Coding Standards

- Follow [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/)
- All PHP files must pass PHPCS with the WordPress standard
- All code must pass PHPStan at level 5
- Use `__()` and `_e()` for all user-facing strings with the `bulk-plugin-installer` text domain

## Commit Messages

This project follows [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/). Every commit message must be structured as:

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

Common types: `feat`, `fix`, `docs`, `style`, `refactor`, `test`, `ci`, `build`, `chore`.

Examples:
- `feat: add batch rollback support`
- `fix(uploader): handle empty ZIP files gracefully`
- `test: add property tests for queue deduplication`
- `ci: update PHP matrix to include 8.3`

## Pull Request Process

1. Create a feature branch from `main`
2. Make your changes following the coding standards above
3. Ensure all tests pass locally: `vendor/bin/phpunit`
4. Push your branch and open a pull request
5. Fill out the PR template checklist
6. Wait for CI checks to pass and request a review

## Testing

- Write unit tests for new functionality in `tests/Unit/`
- Write property-based tests (using Eris) in `tests/Property/`
- Ensure existing tests still pass before submitting a PR
- Aim for meaningful coverage of core logic and edge cases

## Security

If you discover a security vulnerability, please report it responsibly by emailing the maintainers directly rather than opening a public issue.
See [SECURITY.md](SECURITY.md) for details.
