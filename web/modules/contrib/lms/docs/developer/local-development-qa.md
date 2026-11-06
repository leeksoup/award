# Local Development & QA

## Using the QuickStart Repository

The fastest and most reliable way to set up a local development environment for Drupal LMS is by using the official [QuickStart repository](https://github.com/graber-1/drupal_lms_ddev). This repository comes pre-configured with [DDEV](https://ddev.readthedocs.io/), a powerful and easy-to-use local development tool.

The repository's `README.md` file contains the latest installation instructions, which are also outlined below.

## DDEV Setup

To start from scratch with the quick-start repository:

1.  **Install DDEV:** If you don't already have it, install DDEV by following the [official installation instructions](https://ddev.readthedocs.io/en/stable/#installation).  
      
2.  **Start DDEV:** Navigate to the cloned repository folder in your terminal and run `ddev start` — choose Drupal 11 for the project, or change Drupal version in composer.json.  DDEV will automatically configure a web server, database, and other services.  
      
3.  **Install Dependencies:** Run `ddev composer install` to download Drupal core and all required modules.  
      
4.  **Install Drupal LMS:** You can install the site using one of the following commands:
    -   `ddev composer si`: Installs a minimal Drupal site with the full LMS configuration enabled. Ideal for a clean start.  
          
    -   `ddev composer sid`: Installs the site with additional sample content (test users, courses, lessons, and activities). This is highly recommended for development and testing.  
          
    -   `ddev composer test-environment`: Installs a minimal Drupal site configured specifically for running functional JavaScript tests.  
          
5.  **Log In:** If you used `ddev composer sid`, you can log in with one of the default user accounts: LMS Student, LMS Teacher, or LMS Admin.  They all have the password: `123456`.  A password for the User 1 admin account will also be provided in the terminal output.  
      

If you're configuring the site while logged in as User 1 or another Admin user, make sure to assign your account the **LMS Admin** user role, otherwise you may get some unexpected results from Drupal LMS.

After cloning the quick start repository, run `ddev start` followed by `ddev composer sid` to quickly set up a fully functional demo environment with test users, courses, and activities.

## Testing and Quality Assurance

The quick-start repository is configured to make testing and QA straightforward.

### Running Automated Tests

The module includes a suite of automated tests, which will help you ensure that any code changes you've made haven't affected the module's expected function. To run them, you can use the built-in DDEV commands from your project directory:

1.  If you're running tests in this project for the first time, create a browser_output directory. Connect to the DDEV container's shell (`ddev ssh`) and run:  
    `mkdir web/sites/simpletest/browser_output`  
      
2.  From your project directory on the host, run the module's automated tests (Unit, Kernel, and Functional JavaScript):  
    `ddev phpunit lms`  
      

The tests will report any issues, so you can update your code and re-test.  If the tests fail, it can be helpful to view the last file in the `browser_output` directory, which may give you some clues about what went wrong.

### Code Style and Analysis

To ensure code quality, you can run the following analysis tools from your project directory:

-   **PHPStan (Static Analysis):**  
    `ddev phpstan lms`  
      
-   **PHP CodeSniffer (Coding Standards):**  
    `ddev phpcs lms`  
      

**[Previous page: Module Architecture](module-architecture.md)**

**[Next page: Entity Structure](entity-structure.md)**
