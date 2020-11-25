<?php

echo "First message";

sleep(1);

fwrite(STDERR, "An error occurred!");

echo "Second message";

sleep(1);

echo "Third message";

sleep(1);

echo "Final message";