<?php

return [

    /*
    |--------------------------------------------------------------------------
    | First‑Name Variant Map
    |--------------------------------------------------------------------------
    |
    | Map a canonical first name (lowercased) to an array of possible variants
    | (capitalized). Used when trying to match “Matt” to “Matthew,” etc.
    |
    */

    'first_name_variants' => [
        'matthew'     => ['Matthew', 'Mathew', 'Matt'],
        'robert'      => ['Robert', 'Rob', 'Bob', 'Bobby', 'Robb'],
        'joseph'      => ['Joseph', 'Joe', 'Joey'],
        'michael'     => ['Michael', 'Mike'],
        'christopher' => ['Christopher', 'Chris'],
        'andrew'      => ['Andrew', 'Andy'],
        'anthony'     => ['Anthony', 'Tony'],
        'patrick'     => ['Patrick', 'Pat'],
        'charles'     => ['Charles', 'Charlie', 'Chuck'],
        'james'       => ['James', 'Jim', 'Jimmy'],
        'daniel'      => ['Daniel', 'Dan', 'Danny'],
        'david'       => ['David', 'Dave', 'Davy'],
        'alexander'   => ['Alexander', 'Alex'],
        'steven'      => ['Steven', 'Steve'],
        'william'     => ['William', 'Will', 'Bill', 'Billy'],
        'nicholas'    => ['Nicholas', 'Nick', 'Nicky'],
        'john'        => ['John', 'Johnny', 'Jack'],
        'roderick'    => ['Roderick', 'Rod'],
    ],

];
