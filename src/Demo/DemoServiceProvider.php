<?php

namespace Elcreator\aPhalcon\Demo;

use Phalcon\Di\DiInterface;
use Phalcon\Di\ServiceProviderInterface;

/**
 * What a site lists under aphalcon.providers: Phalcon's own provider contract,
 * registering the site's services into the shared DI. The CMS's services are
 * already there - 'cms' is used here to read a system setting.
 */
final class DemoServiceProvider implements ServiceProviderInterface
{
    public function register(DiInterface $di): void
    {
        // Not a static closure: Phalcon binds a definition to the container.
        $di->setShared('greeter', function () use ($di): Greeter {
            return new Greeter((string) $di->get('cms')->setting('site_name', 'this site'));
        });

        // A model behind a service name, so a template asks for
        // {phalcon('documents')->latest()} and never names a class.
        $di->setShared('documents', fn (): Documents => new Documents());
    }
}
