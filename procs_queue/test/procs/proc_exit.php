<?php

echo 'First message';

sleep(2);

fwrite(STDERR, 'A fatal error occurred!');

exit(1);