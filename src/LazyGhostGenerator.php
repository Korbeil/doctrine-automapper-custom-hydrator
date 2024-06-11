<?php

namespace Korbeil\DoctrineAutomapperHydrator;


use Symfony\Component\VarExporter\LazyGhostTrait;

readonly class LazyGhostGenerator
{
    public function __construct(
        private string $lazyGhostDirectory,
        private string $lazyGhostNamespace = 'Korbeil\DoctrineAutomapperHydrator\__LazyGhost__',
    ) {
        $this->checkDirectoryExists();
    }

    private function checkDirectoryExists(): void
    {
        if (!is_dir($this->lazyGhostDirectory)) {
            \mkdir($this->lazyGhostDirectory);
        }
    }

    /**
     * @param class-string $className
     * @return class-string<LazyGhostTrait>
     */
    public function generateAndLoad(string $className): string
    {
        $shortClassName = $this->getShortClassName($className);
        $lazyGhostClass = sprintf('%s\\%s', $this->lazyGhostNamespace, $shortClassName);

        if (!file_exists($path = sprintf('%s/%s.php', $this->lazyGhostDirectory, $shortClassName))) {
            $phpContents = <<<PHP
<?php

namespace $this->lazyGhostNamespace;

use Symfony\Component\VarExporter\LazyGhostTrait;

class $shortClassName extends \\$className
{
    use LazyGhostTrait;
}
PHP;

            file_put_contents($path, $phpContents);
        }

        require_once $path;

        return $lazyGhostClass;
    }

    private function getShortClassName(string $className): string
    {
        /** @var string[] $parts */
        $parts = explode('\\', $className);

        return $parts[\count($parts) - 1];
    }
}