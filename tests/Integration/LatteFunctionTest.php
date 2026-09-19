<?php

declare(strict_types=1);

use Elcreator\aLatteX\LattexEngine;
use Elcreator\aPhalcon\Latte\PhalconExtension;
use Phalcon\Di\Di;

test('{phalcon()} reaches the DI from a Latte template rendered by aLatteX', function (): void {
    if (!class_exists(\Latte\Engine::class) || !class_exists(LattexEngine::class)) {
        skip('Latte and aLatteX are not reachable; build against a core that has them.');
    }
    if (!method_exists(LattexEngine::class, 'addExtension')) {
        skip('This aLatteX has no addExtension() yet.');
    }

    useFakeEvo(documentObject: ['id' => 7, 'pagetitle' => 'Seven']);

    $di = new Di();
    $di->setShared('greeter', fn () => new class {
        public function greet(string $name): string
        {
            return 'Hello, ' . $name;
        }
    });

    $engine = new LattexEngine([]);
    $engine->addExtension(new PhalconExtension(static fn () => $di));

    $out = $engine->render(
        '<p>{phalcon("greeter")->greet($pagetitle)}</p><i>{phalcon()->has("greeter") ? "yes" : "no"}</i>',
        ['id' => 7, 'pagetitle' => 'Seven'],
    );

    expect($out)->toBe('<p>Hello, Seven</p><i>yes</i>');
});
