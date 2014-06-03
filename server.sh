#!/bin/sh
socat tcp-listen:60129,bind=141.62.65.106,reuseaddr,fork exec:./client.php
