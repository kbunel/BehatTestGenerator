Installation
============

Applications that use Symfony Flex
----------------------------------

Open a command console, enter your project directory and execute:

.. code-block:: bash

    $ composer require kbunel/behat-test-generator --dev

Applications that don't use Symfony Flex
----------------------------------------

Step 1: Download the Bundle
~~~~~~~~~~~~~~~~~~~~~~~~~~~

Open a command console, enter your project directory and execute the
following command to download the latest stable version of this bundle:

.. code-block:: terminal

    $ composer require kbunel/behat-test-generator --dev

This command requires you to have Composer installed globally, as explained
in the `installation chapter`_ of the Composer documentation.

Step 2: Enable the Bundle
~~~~~~~~~~~~~~~~~~~~~~~~~

Then, enable the bundle by adding it to the list of registered bundles
in the ``app/AppKernel.php`` file of your project:

.. code-block:: php

    <?php
    // app/AppKernel.php

    // ...
    class AppKernel extends Kernel
    {
        public function registerBundles()
        {
            // ...

            if (in_array($this->getEnvironment(), array('dev', 'test', 'behat'))) {
                $bundles[] = new BehatTestGenerator\BehatTestGeneratorBundle();
                // ...
            );
        }

        // ...
    }

.. _`installation chapter`: https://getcomposer.org/doc/00-intro.md
