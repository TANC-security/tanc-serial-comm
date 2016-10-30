#!/bin/bash
PACKAGE_ROOT=build/deb/tanc-serial-comm
mkdir -p $PACKAGE_ROOT/DEBIAN/
mkdir -p $PACKAGE_ROOT/opt/tanc/serial-comm/vendor/
mkdir -p $PACKAGE_ROOT/opt/tanc/bin
cp bin/serial-comm.php $PACKAGE_ROOT/opt/tanc/bin/
cp -r serial-comm/vendor/* $PACKAGE_ROOT/opt/tanc/serial-comm/vendor/
cp -r debian/* $PACKAGE_ROOT/DEBIAN/
cd $PACKAGE_ROOT
find . -type f ! -regex '.*.hg.*' ! -regex '.*?debian-binary.*' ! -regex '.*?DEBIAN.*' -printf '%P ' | xargs md5sum > DEBIAN/md5sums
cd ..
dpkg -b tanc-serial-comm tanc-serial-comm_0.1-1.deb
#debuild -us -uc
