<?php

require_once 'vendor/autoload.php';

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

class SinceDataExtractor extends NodeVisitorAbstract
{
  private array $sinceData = [];
  private bool|Node $inFunctionNotExistsWrapper = false;
  private ?string $groupedDocComment = null;

  public function enterNode(Node $node)
  {
    $this->processGroupedDocComment($node);

    if (
      $node instanceof Node\Stmt\If_
      && $node->cond instanceof Node\Expr\BooleanNot
      && ($node->cond->expr->name->name ?? '') === 'function_exists'
      // checked function exists outside of WordPress context
      && function_exists($node->cond->expr->args[0]->value->value ?? '')
    ) {
      $this->inFunctionNotExistsWrapper = $node;
    }

    if ($this->inFunctionNotExistsWrapper) {
      return;
    }

    if ($node instanceof Node\Stmt\Class_) {
      $this->processClass($node);
    } elseif ($node instanceof Node\Stmt\Function_) {
      $this->processFunction($node);
    } elseif ($node instanceof Node\Const_) {
      $this->processConstant($node);
    } elseif ($node instanceof Node\Expr\FuncCall) {
      $this->processDefine($node);
      $this->processHooks($node);
    }
  }

  public function leaveNode(Node $node)
  {
    if ($node instanceof Node\Stmt\If_ && $node === $this->inFunctionNotExistsWrapper) {
      $this->inFunctionNotExistsWrapper = false;
    }
  }
  
  private function processGroupedDocComment(Node $node)
  {
    $doc = $this->getPrecedingDocComment($node);
    if ($doc) {
      if (preg_match('/\/\*\*#@\+/', $doc)) {
        $this->groupedDocComment = $doc;
      } elseif (preg_match('/\/\*\*#@\-/', $doc)) {
        $this->groupedDocComment = null; // End the grouped block
      }
    }
  }

  private function getPrecedingDocComment(Node $node): ?string
  {
    if ($node->getAttribute('comments')) {
      return $node->getAttribute('comments')[0]->getText();
    }

    $parent = $node->getAttribute('parent');
    if ($parent instanceof Node\Stmt && $parent->getDocComment()) {
      return $parent->getDocComment()->getText();
    }

    return null;
  }

  private function parseAnnotations(?string $doc): array
  {
    $annotations = [];

    if ($doc) {
      if (preg_match('/@since ([\d.]+)/', $doc, $matches)) {
        $annotations['@since'] = $matches[1];
      }
      if (preg_match('/@deprecated ([\d.]+)/', $doc, $matches)) {
        $annotations['@deprecated'] = $matches[1];
      }
    }

    return $annotations;
  }

  private function processClass(Node\Stmt\Class_ $node)
  {
    $annotations = $this->parseAnnotations($node->getDocComment()?->getText());
    if (!empty($annotations)) {
      $className = $node->name->toString();
      $this->sinceData['class'][$className] = $annotations;
      foreach ($node->getMethods() as $method) {
        $this->processMethod($className, $method);
      }
    }
  }

  private function processMethod(string $className, Node\Stmt\ClassMethod $method)
  {
    $annotations = $this->parseAnnotations($method->getDocComment()?->getText());
    if (!empty($annotations)) {
      $methodName = $method->name->toString();
      $this->sinceData['class'][$className]['method'][$methodName] = $annotations;
    }
  }

  private function processFunction(Node\Stmt\Function_ $node)
  {
    $annotations = $this->parseAnnotations($node->getDocComment()?->getText());
    if (!empty($annotations)) {
      $functionName = $node->name->toString();
      $this->sinceData['function'][$functionName] = $annotations;
    }
  }

  private function processConstant(Node\Const_ $node)
  {
    $annotations = $this->parseAnnotations($node->getDocComment()?->getText());
    if (!empty($annotations)) {
      $constantName = $node->name->toString();
      $this->sinceData['constant'][$constantName] = $annotations;
    }
  }

  private function processDefine(Node\Expr\FuncCall $node)
  {
    if ($node->name instanceof Node\Name && $node->name->toString() === 'define') {
      $args = $node->args;
      if (count($args) >= 2 && $args[0]->value instanceof Node\Scalar\String_) {
        $constantName = $args[0]->value->value;

        $doc = $this->groupedDocComment ?: $this->getPrecedingDocComment($node);
        $annotations = $this->parseAnnotations($doc);
        if (!empty($annotations)) {
          $this->sinceData['constant'][$constantName] = $annotations;
        }
      }
    }
  }

  private function processHooks(Node\Expr\FuncCall $node)
  {
    $hookTypes = ['do_action', 'apply_filters'];
    $functionName = $node->name instanceof Node\Name ? $node->name->toString() : null;

    if (in_array($functionName, $hookTypes) && !empty($node->args)) {
      $hookName = $node->args[0]->value instanceof Node\Scalar\String_
        ? $node->args[0]->value->value
        : null;

      if ($hookName) {
        $doc = $this->getPrecedingDocComment($node);
        $annotations = $this->parseAnnotations($doc);
        if (!empty($annotations)) {
          $this->sinceData['hook'][$functionName][$hookName] = $annotations;
        }
      }
    }
  }

  public function getSinceData(): array
  {
    return $this->sinceData;
  }

  public function clearSinceData(): array
  {
    $this->sinceData = [];
    return $this->sinceData;
  }
}
