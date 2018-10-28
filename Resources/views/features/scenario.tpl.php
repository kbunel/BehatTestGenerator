<?= PHP_EOL ?>
<?php foreach ($routes as $route): ?>
<?php if ($methods && !in_array(strtolower($route['method']), $methods)) continue ?>
<?php if ($tag): ?>
  @<?= $tag ?><?= PHP_EOL ?>
<?php endif ?>
  Scenario: <?= $route['functionName'] ?><?= PHP_EOL ?>
<?php if (strtolower($route['method']) == 'put'): ?>
    Given I add "content-type" header equal to "application/json"<?= PHP_EOL ?>
    Given I send a <?= $route['method']?> request to "<?= $route['path'] ?>" with body:<?= PHP_EOL ?>
    """<?= PHP_EOL ?>
    {<?= PHP_EOL ?>
<?php $i = 1; ?>
<?php foreach ($route['requiredFields'] as $requiredField): ?>
<?php $virgule = count($route['requiredFields']) > $i ? ',' : '' ?>
        "<?= $requiredField['name'] ?>": <?= $requiredField['value'] ?><?= $virgule . PHP_EOL ?>
<?php $i++; ?>
<?php endforeach; ?>
    }<?= PHP_EOL ?>
    """<?= PHP_EOL ?>
<?php else: ?>
    Given I send a <?= $route['method']?> request to "<?= $route['path'] ?>"<?= PHP_EOL ?>
<?php endif ?>
    Then the response status code should be <?= $route['codeResponse'] ?><?= PHP_EOL ?>
<?php endforeach; ?>
