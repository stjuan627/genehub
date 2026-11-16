<?php

declare(strict_types=1);

namespace Drupal\genemedi_i18n_workflow\ParamConverter;

use Drupal\Core\ParamConverter\ParamConverterInterface;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\genemedi_i18n_workflow\TaskManager;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Routing\Route;

/**
 * Selects the exact pre-created translation for authenticated workflow PATCHes.
 */
final class WorkflowEntityConverter implements ParamConverterInterface {

  /**
   * Constructs the scoped parameter converter.
   */
  public function __construct(
    private readonly ParamConverterInterface $inner,
    private readonly TaskManager $tasks,
    private readonly RequestStack $requestStack,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function convert($value, $definition, $name, array $defaults) {
    $request = $this->requestStack->getCurrentRequest();
    $route = $defaults[RouteObjectInterface::ROUTE_OBJECT] ?? NULL;
    $resolved = $request?->attributes->get('_genemedi_i18n_entity');
    if ($request?->isMethod('PATCH') && $request->headers->has('X-Genemedi-I18n-Task')
      && $route?->getMethods() === ['PATCH']
      && (!$resolved || $resolved->uuid() === $value)) {
      try {
        return $this->tasks->resolvePatchEntity($value, $definition, $defaults);
      }
      catch (\InvalidArgumentException | \JsonException $e) {
        throw new BadRequestHttpException($e->getMessage(), $e);
      }
      catch (\RuntimeException $e) {
        throw new ConflictHttpException($e->getMessage(), $e);
      }
    }
    return $this->inner->convert($value, $definition, $name, $defaults);
  }

  /**
   * {@inheritdoc}
   */
  public function applies($definition, $name, Route $route) {
    return $this->inner->applies($definition, $name, $route);
  }

}
