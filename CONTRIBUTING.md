# Contributing to PawHome

Thank you for your interest in contributing to PawHome!

## How to Contribute

1. **Fork the Repository**: Create your personal fork on GitHub.
2. **Clone the Fork**: Clone it to your local XAMPP `htdocs` directory.
3. **Create a Feature Branch**:
   ```bash
   git checkout -b feature/your-feature-name
   ```
4. **Follow Coding Standards**:
   - Write clean, vanilla PHP (compatible with PHP 8.0+).
   - Use prepared statements (`db_run`, `db_all`, `db_one`, `db_exec`) for all SQL interactions.
   - Escape all HTML output with `e()` (`htmlspecialchars`).
   - Validate and verify CSRF tokens for all state-changing POST requests.
   - Do not commit sensitive credentials, API keys, or private upload files.
5. **Test Your Changes**: Verify that local XAMPP pages load without warnings or syntax errors (`php -l filename.php`).
6. **Commit & Push**:
   ```bash
   git commit -m "feat: describe your change"
   git push origin feature/your-feature-name
   ```
7. **Open a Pull Request**: Submit a clear Pull Request explaining your changes and testing steps.

## Code of Conduct

Please maintain respectful, constructive, and friendly communication when participating in this project.
