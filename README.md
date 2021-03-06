This project is not maintened anymore

Add a command to generate API with behat. This command will parse controllers to get the routes and determine create simple test. The tests expectations are based on the HTTP response according to the method.

Installation
============

Applications that use Symfony Flex
----------------------------------

Open a command console, enter your project directory and execute:

```console
$ composer require kbunel/behat-test-generator --dev
```

Applications that don't use Symfony Flex
----------------------------------------

### Step 1: Download the Bundle

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

```console
$ composer require kbunel/behat-test-generator --dev
```

This command requires you to have Composer installed globally, as explained
in the [installation chapter](https://getcomposer.org/doc/00-intro.md)
of the Composer documentation.

### Step 2: Enable the Bundle

Then, enable the bundle by adding it to the list of registered bundles
in the `app/AppKernel.php` file of your project:

```php
<?php
// app/AppKernel.php

// ...
class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            // ...
            new BehatTestGenerator\BehatTestGeneratorBundle();
        );

        // ...
    }

    // ...
}
```

Behat configuration
============

```yaml
default:
    gherkin:
        cache: ~
    extensions:
        Behat\Symfony2Extension:
            kernel:
                env: test
                debug: true
                class: TestAppKernel
                path: app/TestAppKernel.php
        Behat\MinkExtension:
            base_url: http://example.com
            files_path: '%paths.base%/behat/Fixtures/Files'
            sessions:
                default:
                    symfony2: ~
                javascript:
                    selenium2: ~
        Behatch\Extension: ~
    suites:
        default:
            contexts:
                - FeatureContext: { container: '@service_container' }
                - Behat\MinkExtension\Context\MinkContext
                - behatch:context:rest
                - behatch:context:json
                - DatabaseContext: { entityManager: '@doctrine.orm.entity_manager', loader: '@fidry_alice_data_fixtures.doctrine.persister_loader' }
    # Add any context required, like an authentication one
```

Command
============

To generate the tests, run:

```console
$ php bin/console kbunel:behat:generate-test
```

Available options
----------------------------------

##### Add a tag:

```console
$ php bin/console kbunel:behat:generate-test tag=new
```

##### Generate tests from a specific controller with his namespace:

```console
$ php bin/console kbunel:behat:generate-test namespace='App\Controller\MyController'
```

##### Generate tests for specifics method (separated by a comma):

```console
$ php bin/console kbunel:behat:generate-test methods='put,patch'
```

##### Generate tests from a specific namespace:

This option will get all routes from controllers whose namespace begin by the specified one

```console
$ php bin/console kbunel:behat:generate-test fromNamespace='App\Controller\Users'
```

Available configuration
----------------------------------
```yaml
behat_test_generator:
    fixtures:
        folder: 'path/to/the/fixtures/folder'
    features:
        commonFixtures: 'NameOfTheCommonFileFixtures.yaml' # Path to the file used to add the common fixture with the fixtures generated
        authenticationEmails:
            ^admin: 'super_admin@test.com' # ['route_regex' => 'email_used']
            ^user: 'user@test.com'
            # ...
        httpResponses: # http responses used with test expectations
            get: 200
            put: 204
            patch: 204
            post: 201
            delete: 204
```
