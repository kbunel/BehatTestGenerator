Feature: Test Routes for <?= $namespace ?><?= PHP_EOL ?>
  Background:<?= PHP_EOL ?>
    Given the database is empty<?= PHP_EOL ?>
    And the following fixtures files are loaded:<?= PHP_EOL ?>
      | Base/common.yml |<?= PHP_EOL ?>
<?php foreach ($fixturesFilesNames as $fixturesFilesName): ?>
<?php include __DIR__.'imports.tpl.php' ?>
<?php endforeach; ?>
    Given I am authenticated as "<?= $authenticationEmail ?>"<?= PHP_EOL ?>
<?= PHP_EOL ?>