<?php

return [

    // Explicit player-specific spellings, not general Russian suffix substitutions.
    // Canonical display names remain unchanged; callers must still resolve ambiguity.
    'player_name_aliases' => [
        'Igor Chernyshov' => ['Igor Chernyshev'],
        'Lucas Ellinas' => ['Lucas Elinas'],
    ],

    // Exact, user-reviewed lineup references. Do not derive surname or initial aliases
    // from these: a typo such as O. Patterson must not become a general surname rewrite.
    'player_reference_aliases' => [
        'Oskar Pettersson' => ['O. Patterson'],
        'Nikolas Matinpalo' => ['S. Matinpalo'],
        'Eskild Bakke Olsen' => ['EB Olsen', 'E.B. Olsen', 'E. B. Olsen'],
    ],

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
        'nicholas'    => ['Nicholas', 'Nicolas', 'Nick', 'Nicky', 'Nico'],
        'zachary'     => ['Zachary', 'Zach'],
        'john'        => ['John', 'Johnny', 'Jack'],
        'roderick'    => ['Roderick', 'Rod'],
        'mack'        => ['Macklin', 'Mac', 'Mack', 'McDonald', 'MacDonald'],
        'mac'         => ['Macklin', 'Mack', 'Mac', 'McDonald', 'MacDonald'],
        'macklin'     => ['Mack', 'Macklin'],
    ],

];
