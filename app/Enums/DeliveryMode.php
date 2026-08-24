<?php

namespace App\Enums;

enum DeliveryMode: string
{
    case Physical = 'physical';
    case Online = 'online';
    case Hybrid = 'hybrid';
    case Outdoor = 'outdoor';
}
