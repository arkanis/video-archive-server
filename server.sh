#!/bin/sh
socat tcp-listen:60129,bind=127.0.0.1,reuseaddr,fork exec:./client.php
