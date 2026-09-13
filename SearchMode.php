<?php

declare(strict_types=1);

namespace TypechoPlugin\SoMuch;

enum SearchMode: int
{
    case FullText = 1;
    case TitleOnly = 2;
}
