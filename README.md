EwokOS
======

PHP lib and scripts to self-configure and update AWS EC2 nodes on boot, manage configuration/services etc

This project was built as a method to extensibly autoconfigure a pool of AWS EC2 instances on boot.  It's intended to handle multiple classes of nodes, right now the two it implements are WAS nodes (web/application server) and NAS nodes (DRBD/heartbeat replicated NFS instances)

It doesn't perform initial system configuration, just provides a foundation and scaffolding for easily updating instances and managing services without having to rebuild a complete AMI for each change.
