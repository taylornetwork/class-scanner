<?php

namespace Violet\ClassScanner;

use PhpParser\NodeTraverser;
use PhpParser\Parser;
use PhpParser\ParserFactory;
use Violet\ClassScanner\Exception\FileNotFoundException;
use Violet\ClassScanner\Exception\ParsingException;

/**
 * Scanner.
 * @author Riikka Kalliomäki <riikka.kalliomaki@gmail.com>
 * @copyright Copyright (c) 2019 Riikka Kalliomäki
 * @license http://opensource.org/licenses/mit-license.php MIT License
 */
class Scanner
{
    /** @var Parser */
    private $parser;

    /** @var NodeTraverser */
    private $traverser;

    /** @var ClassCollector */
    private $collector;

    /** @var bool */
    private $ignore;

    /** @var bool */
    private $autoload;

    /** @var bool[] */
    private $scannedFiles;

    protected int $maxRecurseLevels = -1;

    public function __construct()
    {
        $this->ignore = false;
        $this->autoload = false;
        $this->collector = new ClassCollector();
        $this->parser = (new ParserFactory())->create(ParserFactory::PREFER_PHP7);
        $this->traverser = new NodeTraverser();
        $this->traverser->addVisitor($this->collector);
        $this->scannedFiles = [];
    }

    public function allowAutoloading(bool $allow = true): self
    {
        $this->autoload = $allow;
        return $this;
    }

    public function ignoreMissing(bool $ignore = true): self
    {
        $this->ignore = $ignore;
        return $this;
    }

    public function getClasses(int $filter = TypeDefinition::TYPE_ANY): array
    {
        $map = $this->collector->getMap();
        $types = $this->collector->getTypes();

        if ($filter === TypeDefinition::TYPE_ANY) {
            return array_values(array_intersect_key($map, $types));
        }

        $classes = [];

        foreach ($types as $name => $type) {
            if ($type & $filter) {
                $classes[] = $map[$name];
            }
        }

        return $classes;
    }

    public function getSubClasses(string $class, int $filter = TypeDefinition::TYPE_CLASS): array
    {
        $this->collector->loadMissing($this->autoload, $this->ignore);

        $map = $this->collector->getMap();
        $children = $this->collector->getChildren();
        $types = $this->collector->getTypes();
        $traverse = array_flip($children[strtolower($class)] ?? []);
        $count = \count($traverse);
        $classes = [];

        for ($i = 0; $i < $count; $i++) {
            $name = key(\array_slice($traverse, $i, 1));

            if (isset($types[$name]) && $types[$name] & $filter) {
                $classes[] = $map[$name];
            }

            if (isset($children[$name])) {
                $traverse += array_flip($children[$name]);
                $count = \count($traverse);
            }
        }

        return $classes;
    }

    /**
     * @param string[] $classes
     * @return TypeDefinition[]
     */
    public function getDefinitions(array $classes): array
    {
        $definitions = $this->collector->getDefinitions();
        $results = [];

        foreach ($classes as $class) {
            $class = strtolower($class);

            if (isset($definitions[$class])) {
                array_push($results, ... $definitions[$class]);
            }
        }

        return $results;
    }

    /**
     * @return int
     */
    public function getMaxRecurseLevels(): int
    {
        return $this->maxRecurseLevels;
    }

    /**
     * @param  int  $levels
     * @return $this
     */
    public function setMaxRecurseLevels(int $levels): self
    {
        $this->maxRecurseLevels = $levels;
        return $this;
    }

    /**
     * @param  string|\SplFileInfo  $filename
     * @return $this
     * @throws ParsingException
     */
    public function scanFile(string|\SplFileInfo $filename): self
    {
        $this->parseFile($filename);
        return $this;
    }

    /**
     * @param  array<string|\SplFileInfo>  $files
     * @return $this
     * @throws ParsingException
     */
    public function scanFiles(array $files): self
    {
        foreach($files as $file) {
            $this->scanFile($file);
        }
        return $this;
    }

    /**
     * @param  string    $directory
     * @param  bool      $recursive
     * @param  int|null  $maxRecurseLevels
     * @return $this
     * @throws ParsingException
     */
    public function scanDirectory(string $directory, bool $recursive = false, ?int $maxRecurseLevels = null): self
    {
        $maxRecurseLevels ??= $this->getMaxRecurseLevels();
        foreach(new \DirectoryIterator($directory) as $item) {
            if($item->isFile()) {
                $this->scanFile($item->getFileInfo());
                continue;
            }

            if($item->isDir() && $recursive) {
                $this->enterDirectory($item, $maxRecurseLevels);
            }
        }

        return $this;
    }

    /**
     * @param  \DirectoryIterator  $directory
     * @param  int                 $levels
     * @return void
     * @throws ParsingException
     */
    private function enterDirectory(\DirectoryIterator $directory, int $levels): void
    {
        $name = $directory->getBasename();

        if($name === '.' || $name === '..' || $levels === 0) {
            return;
        }

        $this->scanDirectory(
            directory: $directory->getPathname(),
            recursive: true,
            maxRecurseLevels: $levels > 0 ? $levels - 1 : $levels
        );
    }

    /**
     * @param  array<string>  $directories
     * @param  bool           $recursive
     * @param  int|null       $maxRecurseLevels
     * @return $this
     * @throws ParsingException
     */
    public function scanDirectories(array $directories, bool $recursive = false, ?int $maxRecurseLevels = null): self
    {
        $maxRecurseLevels ??= $this->getMaxRecurseLevels();
        foreach($directories as $directory) {
            $this->scanDirectory($directory, $recursive, $maxRecurseLevels);
        }
        return $this;
    }

    /**
     * @param  string    $directory
     * @param  int|null  $maxRecurseLevels
     * @return $this
     * @throws ParsingException
     */
    public function scanDirectoryRecursive(string $directory, ?int $maxRecurseLevels = null): self
    {
        $maxRecurseLevels ??= $this->getMaxRecurseLevels();
        $this->scanDirectory($directory, true, $maxRecurseLevels);
        return $this;
    }

    /**
     * @param  array     $directories
     * @param  int|null  $maxRecurseLevels
     * @return $this
     * @throws ParsingException
     */
    public function scanDirectoriesRecursive(array $directories, ?int $maxRecurseLevels = null): self
    {
        $maxRecurseLevels ??= $this->getMaxRecurseLevels();
        $this->scanDirectories($directories, true, $maxRecurseLevels);
        return $this;
    }

    /**
     * @param  mixed  $item
     * @return \SplFileInfo
     */
    protected function getSplFileInfo(mixed $item): \SplFileInfo
    {
        return $item instanceof \SplFileInfo ? $item : new \SplFileInfo((string) $item);
    }

    /**
     * @param  string|\SplFileInfo  $file
     * @return void
     * @throws ParsingException
     */
    protected function parseFile(string|\SplFileInfo $file): void
    {
        $file = $this->getSplFileInfo($file);
        if($file->isFile()) {
            $real = $file->getRealPath();

            if (isset($this->scannedFiles[$real])) {
                return;
            }

            $this->collector->setCurrentFile($real);

            try {
                $this->parse(file_get_contents($real));
                $this->scannedFiles[$real] = true;
            } finally {
                $this->collector->setCurrentFile(null);
            }
        }
    }

    /**
     * @param iterable<string|\SplFileInfo> $files
     * @return Scanner
     * @throws FileNotFoundException
     * @throws ParsingException
     */
    public function scan(iterable $files): self
    {
        foreach ($files as $file) {
            $file = $this->getSplFileInfo($file);

            if ($file->isFile()) {
                $this->parseFile($file);
            } elseif (! $file->isDir() && ! $file->isLink()) {
                throw new FileNotFoundException("The file path '$file' does not exist");
            }
        }

        return $this;
    }

    /**
     * @param string $code
     * @return TypeDefinition[]
     * @throws ParsingException
     */
    public function parse(string $code): array
    {
        try {
            $ast = $this->parser->parse($code);
        } catch (\Exception $exception) {
            $currentFile = $this->collector->getCurrentFile();
            $message = $currentFile === null
                ? sprintf('Error parsing: %s', $exception->getMessage())
                : sprintf("Error parsing '%s': %s", $currentFile, $exception->getMessage());
            throw new ParsingException($message, 0, $exception);
        }

        $this->traverser->traverse($ast);

        return $this->collector->getCollected();
    }
}
