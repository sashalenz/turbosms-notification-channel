<?php

declare(strict_types=1);

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->each->not->toBeUsed();

arch('strict types are used in all files')
    ->expect('Sashalenz\TurboSms')
    ->toUseStrictTypes();
