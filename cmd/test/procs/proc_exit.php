<?php

echo 'First message';

sleep(2);

fwrite(STDERR, 'An fatal error occurred!');

exit(1);