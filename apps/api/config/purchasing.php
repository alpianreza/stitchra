<?php

return [
    'price_tolerance_pct' => (float) env('PURCHASING_PRICE_TOLERANCE_PCT', 2.0),
    'qty_tolerance_pct' => (float) env('PURCHASING_QTY_TOLERANCE_PCT', 2.0),
];
