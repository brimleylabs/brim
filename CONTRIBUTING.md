# Contributing to Brim

Thank you for considering contributing to Brim! This document outlines the process for contributing to the project.

## Code of Conduct

Please be respectful and constructive in all interactions. We're all here to build something great together.

## How Can I Contribute?

### Reporting Bugs

Before creating bug reports, please check existing issues to avoid duplicates. When creating a bug report, include:

- **Clear title** describing the issue
- **Steps to reproduce** the behavior
- **Expected behavior** vs what actually happened
- **Environment details**: PHP version, Laravel version, PostgreSQL version, Ollama version
- **Code samples** if applicable

### Suggesting Features

Feature requests are welcome! Please include:

- **Clear description** of the feature
- **Use case** explaining why this would be useful
- **Possible implementation** if you have ideas

### Pull Requests

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Write or update tests as needed
5. Ensure all tests pass (`composer test`)
6. Follow the coding style (PSR-12)
7. Commit your changes (`git commit -m 'Add amazing feature'`)
8. Push to your branch (`git push origin feature/amazing-feature`)
9. Open a Pull Request

## Development Setup

```bash
# Clone your fork
git clone https://github.com/your-username/brim.git
cd brim

# Install dependencies
composer install

# Run tests
composer test

# Run code style fixer
composer format
```

## Testing

```bash
# Run all tests
composer test

# Run specific test file
./vendor/bin/phpunit tests/Feature/SemanticSearchTest.php

# Run with coverage
composer test-coverage
```

## Coding Standards

- Follow PSR-12 coding style
- Add docblocks to public methods
- Write descriptive commit messages
- Keep PRs focused on a single feature/fix

## Documentation

If your change affects how users interact with Brim:

- Update the README.md if needed
- Add/update docblocks
- Consider adding examples

## Questions?

Feel free to open an issue with the "question" label if you need help or clarification.

Thank you for contributing!
