#!/bin/bash
REV=$1
BUILD=$2
if [ -z $BUILD ]
then
	BUILD=1
fi
if [ -z $REV ]
then
	echo "must supply a version number like 0.1.2"
	exit
fi


PACKAGE_ROOT=build/deb/tanc-serial-comm
rm -Rf $PACKAGE_ROOT
mkdir -p $PACKAGE_ROOT/DEBIAN/
mkdir -p $PACKAGE_ROOT/opt/tanc/serial-comm/vendor/
mkdir -p $PACKAGE_ROOT/opt/tanc/systemd/
mkdir -p $PACKAGE_ROOT/opt/tanc/bin
cp bin/serial-comm.php $PACKAGE_ROOT/opt/tanc/bin/
cp -r serial-comm/vendor/* $PACKAGE_ROOT/opt/tanc/serial-comm/vendor/
cp -r systemd/* $PACKAGE_ROOT/opt/tanc/systemd/
cp -r debian/* $PACKAGE_ROOT/DEBIAN/
echo "Version:  $REV" >> $PACKAGE_ROOT/DEBIAN/control
cd $PACKAGE_ROOT
find . -type f ! -regex '.*.hg.*' ! -regex '.*?debian-binary.*' ! -regex '.*?DEBIAN.*' -printf '%P ' | xargs md5sum > DEBIAN/md5sums
cd ..
dpkg -b tanc-serial-comm tanc-serial-comm_$REV-$BUILD.deb
#debuild -us -uc
