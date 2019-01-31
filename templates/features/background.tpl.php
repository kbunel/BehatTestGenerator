Feature: Test Routes for <?= $namespace ?><?= PHP_EOL ?>
  Background:<?= PHP_EOL ?>
    Given the following fixtures files are loaded:<?= PHP_EOL ?>
<?php if ($commonFixtures): ?>
      | <?= $commonFixtures ?> |<?= PHP_EOL ?>
<?php endif ?>
<?php foreach ($fixturesFilesNames as $fixturesFilesName): ?>
<?php include __DIR__.'/imports.tpl.php' ?>
<?php endforeach; ?>
<?php if ($authenticationEmail): ?>
        Given I am authenticated as "<?= $authenticationEmail ?>"<?= PHP_EOL ?>
<?php endif ?>
<?= PHP_EOL ?>