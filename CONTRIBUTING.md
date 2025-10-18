# Contributing to PHP EasyCache

First off, thank you for considering contributing to PHP EasyCache! It's people like you that make PHP EasyCache such a great tool.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [How Can I Contribute?](#how-can-i-contribute)
- [Development Setup](#development-setup)
- [Coding Standards](#coding-standards)
- [Testing Guidelines](#testing-guidelines)
- [Pull Request Process](#pull-request-process)
- [Reporting Bugs](#reporting-bugs)
- [Suggesting Enhancements](#suggesting-enhancements)

---

## Code of Conduct

This project and everyone participating in it is governed by our [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code.

---

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check the existing issues to avoid duplicates. When you create a bug report, include as many details as possible:

**Great Bug Reports** include:

- A clear and descriptive title
- Exact steps to reproduce the problem
- The expected behavior
- The actual behavior
- Screenshots if applicable
- Your environment (PHP version, OS, extensions)
- Code samples that demonstrate the issue

**Example:**

```markdown
## Bug: FileStorage fails with permission error

**Environment:**
- PHP 8.1.15
- Ubuntu 22.04
- No special extensions

**Steps to Reproduce:**
1. Create FileStorage with path `/var/cache/test`
2. Call `$storage->set('key', 'value', 3600)`
3. Error occurs

**Expected:** File should be created
**Actual:** Permission denied error

**Code:**
\`\`\`php
$storage = new FileStorage('/var/cache/test');
$storage->set('key', 'value', 3600); // Fails here
\`\`\`
```

### Suggesting Enhancements

Enhancement suggestions are tracked as GitHub issues. When creating an enhancement suggestion:

- Use a clear and descriptive title
- Provide a detailed description of the proposed feature
- Explain why this enhancement would be useful
- Provide code examples if applicable
- List any alternatives you've considered

---

## Development Setup

### Prerequisites

- PHP 8.1 or higher
- Composer
- Git

### Setting Up Your Development Environment

1. **Fork the repository**

2. **Clone your fork:**
```bash
git clone https://github.com/YOUR_USERNAME/php-easycache.git
cd php-easycache
```

3. **Install dependencies:**
```bash
composer install
```

4. **Create a branch:**
```bash
git checkout -b feature/your-feature-name
# or
git checkout -b fix/your-bug-fix
```

### Project Structure

```
php-easycache/
├── src/
│   ├── Cache/              # Core cache implementation
│   ├── Storage/            # Storage backends
│   ├── Serialization/      # Serializers
│   ├── Compression/        # Compressors
│   ├── Util/              # Utilities
│   ├── Exceptions/        # Custom exceptions
│   └── Laravel/           # Laravel integration
├── tests/                 # Test files (mirrors src/)
├── examples/              # Example code
├── config/               # Configuration files
└── docs/                 # Documentation
```

---

## Coding Standards

We follow **PSR-12** coding standards.

### Key Points

1. **Type Declarations:** Always use strict types
```php
<?php
declare(strict_types=1);
```

2. **Type Hints:** Use type hints for all parameters and return types
```php
public function get(string $key): ?string
{
    // ...
}
```

3. **Docblocks:** Add docblocks for complex methods
```php
/**
 * Retrieve data from cache with SWR support.
 * 
 * @param string $key Cache key
 * @param callable $producer Function to produce fresh value
 * @param int $ttl Time to live in seconds
 * @return mixed Cached or fresh value
 */
public function getOrSetSWR(string $key, callable $producer, int $ttl): mixed
{
    // ...
}
```

4. **Error Handling:** Use proper error handling
```php
// Good ✅
try {
    $result = $storage->set($key, $value, $ttl);
    if (!$result) {
        $this->log('warning', "Failed to set cache key: {$key}");
    }
} catch (\Throwable $e) {
    $this->log('error', "Cache error: " . $e->getMessage());
    return false;
}

// Bad ❌
@$storage->set($key, $value, $ttl);
```

5. **Naming Conventions:**
- Classes: `PascalCase`
- Methods: `camelCase`
- Constants: `SCREAMING_SNAKE_CASE`
- Properties: `camelCase`

### Running Code Quality Checks

```bash
# Check coding standards
composer cs

# Fix coding standards automatically
composer cs:fix

# Run static analysis
composer stan
```

---

## Testing Guidelines

We maintain high test coverage. All new features and bug fixes should include tests.

### Writing Tests

1. **Test File Location:** Mirror the source structure
```
src/Storage/FileStorage.php
tests/Storage/FileStorageTest.php
```

2. **Test Naming:** Use descriptive names
```php
// Good ✅
public function testSetAndGetWithLargePayload(): void
public function testDeleteNonExistentKeyReturnsTrue(): void
public function testSWRServesStaleDataDuringRefresh(): void

// Bad ❌
public function testSet(): void
public function test1(): void
```

3. **Test Structure:** Follow AAA pattern (Arrange, Act, Assert)
```php
public function testCacheHitReturnsStoredValue(): void
{
    // Arrange
    $key = 'test_key';
    $value = 'test_value';
    $this->cache->set($key, $value, 3600);
    
    // Act
    $result = $this->cache->get($key);
    
    // Assert
    $this->assertEquals($value, $result);
}
```

4. **Cleanup:** Always clean up in tearDown
```php
protected function tearDown(): void
{
    $this->cache->clear();
    parent::tearDown();
}
```

5. **Edge Cases:** Test edge cases and error conditions
```php
public function testEmptyKeyThrowsException(): void
{
    $this->expectException(InvalidArgument::class);
    $this->cache->set('', 'value');
}
```

### Running Tests

```bash
# Run all tests
composer test

# Run specific test file
./vendor/bin/phpunit tests/Storage/FileStorageTest.php

# Run with coverage
composer test -- --coverage-html coverage

# Run specific test method
./vendor/bin/phpunit --filter testSetAndGet
```

### Test Coverage Requirements

- New features: **minimum 80% coverage**
- Bug fixes: Test must reproduce the bug and verify the fix
- Critical paths: **100% coverage**

---

## Pull Request Process

### Before Submitting

1. **Update your branch:**
```bash
git fetch upstream
git rebase upstream/main
```

2. **Run all checks:**
```bash
composer test
composer stan
composer cs
```

3. **Update documentation** if needed

4. **Add/update tests** for your changes

### Submitting the PR

1. **Push your branch:**
```bash
git push origin feature/your-feature-name
```

2. **Create Pull Request** on GitHub

3. **Fill out the PR template:**

```markdown
## Description
Brief description of changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## Testing
- [ ] Tests pass locally
- [ ] New tests added
- [ ] Coverage maintained/improved

## Checklist
- [ ] Code follows PSR-12
- [ ] PHPStan passes
- [ ] Documentation updated
- [ ] CHANGELOG.md updated
```

4. **Respond to feedback** promptly

### PR Review Process

1. Automated checks must pass (CI/CD)
2. At least one maintainer approval required
3. No unresolved conversations
4. Branch must be up to date with main

---

## Commit Message Guidelines

We follow [Conventional Commits](https://www.conventionalcommits.org/).

### Format

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Types

- `feat`: New feature
- `fix`: Bug fix
- `docs`: Documentation only
- `style`: Code style changes (formatting, etc.)
- `refactor`: Code refactoring
- `perf`: Performance improvements
- `test`: Adding or updating tests
- `chore`: Maintenance tasks

### Examples

```bash
# Good ✅
feat(storage): add support for DynamoDB storage backend
fix(cache): prevent race condition in SWR refresh
docs(readme): add examples for multi-tier caching
test(compression): add tests for Zstd compressor

# Bad ❌
update stuff
fixed bug
more tests
```

---

## Development Tips

### Debugging Tests

```php
// Add verbose output
$this->cache = new MultiTierCache(
    [$storage],
    $serializer,
    $compressor,
    3600,
    new ConsoleLogger() // Helps debug issues
);
```

### Testing Different PHP Versions

```bash
# Using Docker
docker run --rm -v $(pwd):/app -w /app php:8.1-cli composer test
docker run --rm -v $(pwd):/app -w /app php:8.2-cli composer test
docker run --rm -v $(pwd):/app -w /app php:8.3-cli composer test
```

### Performance Testing

```php
// Add timing to tests
$start = microtime(true);
$this->cache->set('key', $value, 3600);
$elapsed = microtime(true) - $start;
$this->assertLessThan(0.01, $elapsed, 'Set operation too slow');
```

---

## Architecture Guidelines

### Adding a New Storage Backend

1. **Implement `StorageInterface`:**
```php
final class MyStorage implements StorageInterface
{
    public function get(string $key): ?string { }
    public function set(string $key, string $payload, int $ttl): bool { }
    public function delete(string $key): bool { }
    public function has(string $key): bool { }
    public function clear(): bool { }
    public function prune(): int { }
}
```

2. **Add tests:**
```php
class MyStorageTest extends TestCase
{
    // Test all interface methods
}
```

3. **Update documentation:**
- Add to README.md
- Add example to EXAMPLES.md
- Update configuration guide

### Adding a New Serializer

1. **Implement `SerializerInterface`:**
```php
final class MySerializer implements SerializerInterface
{
    public function serialize(mixed $value): string { }
    public function deserialize(string $payload): mixed { }
    public function name(): string { }
}
```

2. **Ensure backward compatibility** - old cached data should still be readable

### Adding a New Compressor

Similar to serializer - implement `CompressorInterface`.

---

## Release Process

(For maintainers only)

1. Update CHANGELOG.md
2. Update version in composer.json
3. Tag release: `git tag -a v3.x.x -m "Release v3.x.x"`
4. Push tags: `git push origin --tags`
5. Create GitHub release with changelog

---

## Questions?

- 💬 [GitHub Discussions](https://github.com/iprodev/php-easycache/discussions)
- 📧 Email: dev@iprodev.com
- 🐛 [Issue Tracker](https://github.com/iprodev/php-easycache/issues)

---

## Recognition

Contributors are recognized in:
- CONTRIBUTORS.md file
- GitHub contributors page
- Release notes

Thank you for contributing! 🎉
