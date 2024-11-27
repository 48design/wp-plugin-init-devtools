<?php

require_once 'vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class UsageDataExtractor extends NodeVisitorAbstract
{
  private ?array $sinceData = null;
  private string $minVersion = '1.0';
  private array $usageData = [];
  
  public string $currentFile = '';

  public function enterNode(Node $node)
  {
    if($this->sinceData === null) {
      throw new Exception('Since data not provided, use UsageDataExtractor->setSinceData($sinceData)');
    }

    // Process function calls
    if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
      $this->processFunctionCall($node);
      $this->processHooks($node);
    }

    // Process class instantiations
    if ($node instanceof Node\Expr\New_ && $node->class instanceof Node\Name) {
      $this->processClassUsage($node->class);
    }

    // Process method calls (static or instance)
    if ($node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\MethodCall) {
      $this->processMethodCall($node);
    }

    // Process constants
    if ($node instanceof Node\Expr\ConstFetch || $node instanceof Node\Expr\ClassConstFetch) {
      $this->processConstantUsage($node);
    }
  }

  public function setSinceData($sinceData) {
    $this->sinceData = $sinceData;
  }

  private function processFunctionCall(Node\Expr\FuncCall $node)
  {
    $functionName = $node->name->toString();
    $functionData = $this->sinceData['function'][$functionName] ?? null;
    
    if($functionData === null) return;

    $sinceVersion = $functionData['@since'];

    $this->updateUsageData($this->usageData['function'][$functionName], $functionData, $node);

    if($functionName)

    $this->updateMinVersion($sinceVersion);
  }

  private function processHooks(Node $node) {
    
    $hookTypes = ['add_action', 'add_filter'];
    $hookCalls = ['do_action', 'apply_filters'];
    $functionName = $node->name instanceof Node\Name ? $node->name->toString() : null;

    if (in_array($functionName, $hookTypes) && !empty($node->args)) {
      $hookName = $node->args[0]->value instanceof Node\Scalar\String_
        ? $node->args[0]->value->value
        : null;

      if ($hookName) {
        $hookFunction = $hookCalls[array_search($functionName, $hookTypes)];
        $hookData = $this->sinceData['hook'][$hookFunction][$hookName] ?? null;
        
        if($hookData === null) return;

        $sinceVersion = $hookData['@since'];

        $this->updateUsageData($this->usageData['hook'][$hookFunction][$hookName], $hookData, $node);
        $this->updateMinVersion($sinceVersion);
      }
    }
  }

  private function processMethodCall(Node $node)
  {
    $className = $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name
        ? $node->class->toString()
        : '$object';

    $classData = $this->sinceData['class'][$className] ?? null;

    if($classData !== null) {
      $sinceVersion = $classData['@since'];
  
      $this->updateUsageData($this->usageData['class'][$className], $classData, $node);
      $this->updateMinVersion($sinceVersion);
    }

    $methodName = $node->name instanceof Node\Identifier ? $node->name->toString() : null;

    if ($methodName) {
      $methodData = $this->sinceData['class'][$className]['method'][$methodName] ?? null;

      if($methodData === null) return;

      $sinceVersion = $methodData['@since'];

      $this->updateUsageData($this->usageData['class'][$className]['method'][$methodName], $methodData, $node);
      $this->updateMinVersion($sinceVersion);
    }
  }

  private function processClassUsage(Node\Name $node)
  {
    $className = $node->toString();
    $classData = $this->sinceData['class'][$className] ?? null;

    if($classData === null) return;

    $sinceVersion = $classData['@since'];

    $this->updateUsageData($this->usageData['class'][$className], $classData, $node);
    $this->updateMinVersion($sinceVersion);
  }

  private function processConstantUsage(Node $node)
  {
    $constantName = null;

    if ($node instanceof Node\Expr\ConstFetch) {
      $constantName = $node->name->toString();
    } elseif ($node instanceof Node\Expr\ClassConstFetch && $node->class instanceof Node\Name) {
      $constantName = $node->class->toString() . '::' . $node->name->toString();
    }

    if ($constantName) {
      $constantData = $this->sinceData['constant'][$constantName] ?? null;

      if($constantData === null) return;

      $sinceVersion = $constantData['@since'];

      $this->updateUsageData($this->usageData['constant'][$constantName], $constantData, $node);
      $this->updateMinVersion($sinceVersion);
    }
  }

  private function updateUsageData(&$dataPoint, $data, $node) {
    if(!isset($data['_where'])) {
      $data['_where'] = array();
    }
    $data['_where'][] = $this->currentFile . ':'. $node->getLine();
    $dataPoint = $data;
  }

  private function updateMinVersion(?string $sinceVersion)
  {
    if ($sinceVersion !== null) {
      $this->minVersion = WordPressVersionChecker::compareMinVersions($this->minVersion, $sinceVersion);
    }
  }

  public function getMinVersion(): string
  {
    return $this->minVersion;
  }

  public function getUsageData(): array
  {
    return $this->usageData;
  }

  public function clearUsageData(): void
  {
    $this->minVersion = '1.0';
    $this->usageData = [];
  }
}
  