## Description

<!-- Briefly describe the changes in this PR. -->

## Related Issue

<!-- Link to the issue this PR addresses, e.g. Fixes #123 -->

## Type of Change

- [ ] Bug fix (non-breaking change that fixes an issue)
- [ ] New feature (non-breaking change that adds functionality)
- [ ] Breaking change (fix or feature that would cause existing functionality to change)
- [ ] Refactor (code change that neither fixes a bug nor adds a feature)
- [ ] Documentation update
- [ ] CI/CD change

## Code Review Checklist

### Tests
- [ ] All existing tests pass (`vendor/bin/phpunit`)
- [ ] New unit tests added for new functionality
- [ ] Property-based tests added where applicable
- [ ] Edge cases are covered

### Coding Standards
- [ ] Code follows WordPress Coding Standards (PHPCS passes)
- [ ] PHPStan passes at level 5
- [ ] No new PHPMD warnings introduced

### Security
- [ ] All AJAX handlers verify nonce with `wp_verify_nonce()`
- [ ] All AJAX handlers check capabilities with `current_user_can()`
- [ ] File operations use `WP_Filesystem` API (no direct `file_put_contents` / `unlink`)
- [ ] No path traversal vulnerabilities introduced
- [ ] User input is sanitized and validated

### Internationalization
- [ ] All user-facing strings use `__()` / `_e()` / `esc_html__()` with `bulk-plugin-installer` text domain
- [ ] No hardcoded English strings in PHP output

### Accessibility
- [ ] Interactive elements have ARIA labels
- [ ] UI changes are keyboard navigable
- [ ] Status changes use ARIA live regions

### General
- [ ] Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0/)
- [ ] No debug code or `var_dump` / `error_log` left in
- [ ] Documentation updated if needed
