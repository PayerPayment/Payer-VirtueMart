#!/bin/bash

# Payer Zip Exporter

# Clear yunk before zip
function clearFiles {
	find . -iname *DS_Store* -type f | xargs rm -rf;
	echo cleared .DS_Store;

	find . -iname CVS -type d | xargs rm -rf;
	echo cleared CVS;

	find . -iname __MACOSX -type d | xargs rm -rf;
	echo cleared __MACOSX;
}

# Creates zip files of each directory in the current folder
function generateZipFiles {
	for i in */; 
		do zip -r "${i%/}.zip" "$i"; 
	done
}

# Remove all recents zips in the current folder
function deleteZipFiles {
	for i in *.zip;
		do rm -rf "${i%}";
		echo removed: "${i%}";
	done
}

clearFiles
deleteZipFiles
generateZipFiles
