<?php

namespace VirtualFileSystem;

use VirtualFileSystem\Structure\Directory;
use VirtualFileSystem\Structure\File;

class WrapperTest extends \PHPUnit_Framework_TestCase
{
    public function testSchemeStripping()
    {
        $c = new Wrapper();

        $this->assertEquals('/1/2/3/4', $c->stripScheme('test://1/2/3/4'));
        $this->assertEquals('/', $c->stripScheme('test://'));
        $this->assertEquals('/', $c->stripScheme('test:///'));

    }

    public function testContainerIsReturnedFromContext()
    {
        stream_context_set_default(array('contextContainerTest' => array('Container' => 'test')));

        $c = new Wrapper();

        $this->assertEquals('test', $c->getContainerFromContext('contextContainerTest://file'));
        $this->assertEquals('test', $c->getContainerFromContext('contextContainerTest://'));

    }

    public function testFileExists()
    {
        $fs = new FileSystem();
        $fs->root()->addDirectory($d = new Directory('dir'));
        $d->addFile(new File('file'));
        $d->addDirectory(new Directory('dir'));

        $this->assertTrue(file_exists($fs->path('/dir/file')));
        $this->assertTrue(file_exists($fs->path('/dir')));
        $this->assertFalse(file_exists($fs->path('/dir/fileNotExist')));

    }

    public function testIsDir()
    {
        $fs = new FileSystem();
        $fs->root()->addDirectory($d = new Directory('dir'));
        $d->addFile(new File('file'));
        $d->addDirectory(new Directory('dir'));

        $this->assertFalse(is_dir($fs->path('/dir/file')));
        $this->assertTrue(is_dir($fs->path('/dir')));
        $this->assertTrue(is_dir($fs->path('/dir/dir')));
        $this->assertTrue(is_dir($fs->path('/')));

    }

    public function testIsFile()
    {
        $fs = new FileSystem();
        $fs->root()->addDirectory($d = new Directory('dir'));
        $d->addFile(new File('file'));
        $fs->root()->addFile(new File('file2'));
        $d->addDirectory(new Directory('dir'));

        $this->assertTrue(is_file($fs->path('/dir/file')));
        $this->assertFalse(is_file($fs->path('/dir')));
        $this->assertFalse(is_file($fs->path('/dir/dir')));
        $this->assertFalse(is_file($fs->path('/')));
        $this->assertTrue(is_file($fs->path('/file2')));

    }

    public function testChmod()
    {
        $fs = new FileSystem();
        $path = $fs->path('/');

        chmod($path, 0777);
        $this->assertEquals(0777 | Directory::S_IFTYPE, $fs->root()->mode());

        $fs->root()->chmod(0755);
        $this->assertEquals(0755 | Directory::S_IFTYPE, fileperms($path));

        //accessing non existent file should return false
        $this->assertFalse(chmod($fs->path('/nonExistingFile'), 0777));

    }

    public function testChownByName()
    {
        if (posix_getuid() == 0) {
            $this->markTestSkipped(
                'No point testing if user is already root. \Php unit shouldn\'t be run as root user.'
            );
        }

        $fs = new FileSystem();

        chown($fs->path('/'), 'root');
        $this->assertEquals('root', posix_getpwuid(fileowner($fs->path('/')))['name']);

        $nextCurrentOwner = fileowner($fs->path('/'));

        return;

    }

    public function testChownById()
    {
        if (posix_getuid() == 0) {
            $this->markTestSkipped(
                'No point testing if user is already root. Php unit shouldn\'t be run as root user.'
            );
        }

        $fs = new FileSystem();

        chown($fs->path('/'), 0);

        $this->assertEquals(0, fileowner($fs->path('/')));

    }

    public function testChgrpByName()
    {
        if (posix_getgid() == 0) {
            $this->markTestSkipped(
                'No point testing if group is already root. Php unit shouldn\'t be run as root group.'
            );
        }

        $fs = new FileSystem();

        //lets workout available group
        //this is needed to find string name of group root belongs to
        $group = posix_getgrgid(posix_getpwuid(0)['gid'])['name'];

        chgrp($fs->path('/'), $group);

        $this->assertEquals($group, posix_getgrgid(filegroup($fs->path('/')))['name']);
    }

    public function testChgrpById()
    {
        if (posix_getgid() == 0) {
            $this->markTestSkipped(
                'No point testing if group is already root. Php unit shouldn\'t be run as root group.'
            );
        }

        $fs = new FileSystem();

        //lets workout available group
        $group = posix_getpwuid(0)['gid'];

        chgrp($fs->path('/'), $group);

        $this->assertEquals($group, filegroup($fs->path('/')));
    }

    public function testMkdir()
    {
        $fs = new FileSystem();

        mkdir($fs->path('/dir'));

        $this->assertTrue(file_exists($fs->path('/dir')));
        $this->assertTrue(is_dir($fs->path('/dir')));

    }

    public function testMkdirCatchesClashes()
    {
        $fs = new FileSystem();

        mkdir($fs->path('/dir'));
        @mkdir($fs->path('/dir'));

        $error = error_get_last();

        $this->assertEquals($error['message'], 'dir already exists');
    }

    public function testMkdirRecursive()
    {
        $fs = new FileSystem();

        mkdir($fs->path('/dir/dir2'), 0777, true);

        $this->assertTrue(file_exists($fs->path('/dir/dir2')));
        $this->assertTrue(is_dir($fs->path('/dir/dir2')));

    }

    public function testStreamWriting()
    {
        $fs = new FileSystem();

        file_put_contents($fs->path('/file'), 'data');

        $this->assertEquals('data', $fs->container()->fileAt('/file')->data());

        //long strings
        file_put_contents($fs->path('/file2'), str_repeat('data ', 5000));

        $this->assertEquals(str_repeat('data ', 5000), $fs->container()->fileAt('/file2')->data());

        //truncating
        file_put_contents($fs->path('/file'), 'data2');

        $this->assertEquals('data2', $fs->container()->fileAt('/file')->data());

        //appending
        file_put_contents($fs->path('/file'), 'data3', FILE_APPEND);

        $this->assertEquals('data2data3', $fs->container()->fileAt('/file')->data());

        $handle = fopen($fs->path('/file2'), 'w');

        fwrite($handle, 'data');
        $this->assertEquals('data', $fs->container()->fileAt('/file2')->data());

        fwrite($handle, '2');
        $this->assertEquals('data2', $fs->container()->fileAt('/file2')->data(), 'Pointer advanced');

        fwrite($handle, 'data', 1);
        $this->assertEquals('data2d', $fs->container()->fileAt('/file2')->data(), 'Written with limited lenghth');

    }

    public function testStreamReading()
    {
        $fs = new FileSystem();
        $fs->container()->createFile('/file', 'test data');

        $this->assertEquals('test data', file_get_contents($fs->path('/file')));

        //long string
        $fs->container()->createFile('/file2', str_repeat('test data', 5000));
        $this->assertEquals(str_repeat('test data', 5000), file_get_contents($fs->path('/file2')));

    }

    public function testOpeningForReadingOnNonExistingFails() {

        $fs = new FileSystem();

        @fopen($fs->path('/nonExistingFile'), 'r');

        $error = error_get_last();

        $this->assertStringMatchesFormat('fopen(%s://nonExistingFile): failed to open stream: %s', $error['message']);

    }

    public function testOpeningForWritingCorrectlyOpensAndTruncatesFile()
    {
        $fs = new FileSystem();

        $handle = fopen($fs->path('/nonExistingFile'), 'w');

        $this->assertTrue(is_resource($handle));

        $file = $fs->container()->createFile('/file', 'data');

        $handle = fopen($fs->path('/file'), 'w');

        $this->assertTrue(is_resource($handle));
        $this->assertEmpty($file->data());
    }

    public function testOpeningForAppendingDoesNotTruncateFile()
    {

        $fs = new FileSystem();
        $file = $fs->container()->createFile('/file', 'data');

        $handle = fopen($fs->path('/file'), 'a');

        $this->assertTrue(is_resource($handle));
        $this->assertEquals('data', $file->data());

    }

    public function testCreatingFileWhileOpeningFailsCorrectly()
    {

        $fs = new FileSystem();

        @fopen($fs->path('/dir/file'), 'w');

        $error = error_get_last();

        $this->assertStringMatchesFormat('fopen(%s://dir/file): failed to open stream: %s', $error['message']);

    }

    public function testFileGetContentsOffsetsAndLimitsCorrectly()
    {

        $fs = new FileSystem();
        $file = $fs->container()->createFile('/file', '--data--');

        $this->assertEquals('data', file_get_contents($fs->path('/file'), false, null, 2, 4));

    }

    public function testFileSeeking()
    {
        $fs = new FileSystem();
        $fs->container()->createFile('/file', 'data');

        $handle = fopen($fs->path('/file'), 'r');

        fseek($handle, 2);
        $this->assertEquals(2, ftell($handle));

        fseek($handle, 1, SEEK_CUR);
        $this->assertEquals(3, ftell($handle));

        fseek($handle, 6, SEEK_END);
        $this->assertEquals(10, ftell($handle), 'End of file + 6 is 10');
    }

    public function testFileTruncating()
    {
        $fs = new FileSystem();
        $file = $fs->container()->createFile('/file', 'data--');

        $handle = fopen($fs->path('/file'), 'a'); //has to opened for append otherwise file is automatically truncated by 'w' opening mode

        ftruncate($handle, 4);

        $this->assertEquals('data', $file->data());

    }

    public function testOpeningModesAreHandledCorrectly()
    {

        $fs = new FileSystem();
        $file = $fs->container()->createFile('/file', 'data');

        $handle = fopen($file, 'r');
        $this->assertEquals('data', fread($handle, 4), 'Contents can be read in read mode');
        $this->assertEquals(0, fwrite($handle, '--'), '0 bytes should be written in readonly mode');

        $handle = fopen($file, 'r+');
        $this->assertEquals('data', fread($handle, 4), 'Contents can be read in extended read mode');
        $this->assertEquals(2, fwrite($handle, '--'), '2 bytes should be written in extended readonly mode');

        $handle = fopen($file, 'w');
        $this->assertEquals(4, fwrite($handle, 'data'), '4 bytes written in writeonly mode');
        fseek($handle, 0);
        $this->assertEmpty(fread($handle, 4), 'No bytes read in write only mode');

        $handle = fopen($file, 'w+');
        $this->assertEquals(4, fwrite($handle, 'data'), '4 bytes written in extended writeonly mode');
        fseek($handle, 0);
        $this->assertEquals('data', fread($handle, 4), 'Bytes read in extended write only mode');

        $file->setData('data');

        $handle = fopen($file, 'a');
        $this->assertEquals(4, fwrite($handle, 'data'), '4 bytes written in append mode');
        fseek($handle, 0);
        $this->assertEmpty(fread($handle, 4), 'No bytes read in append mode');

        $handle = fopen($file, 'a+');
        $this->assertEquals(4, fwrite($handle, 'data'), '4 bytes written in extended append mode');
        fseek($handle, 0);
        $this->assertEquals('datadata', fread($handle, 8), 'Bytes read in extended append mode');

    }

    public function testFileTimesAreModifiedCorerectly()
    {

        $fs = new FileSystem();
        $file = $fs->container()->createFile('/file', 'data');

        $stat = stat($fs->path('/file'));
        $atime = $stat['atime'];
        $mtime = $stat['mtime'];
        $ctime = $stat['ctime'];

        $this->assertNotEquals(0, $stat['atime']);
        $this->assertNotEquals(0, $stat['mtime']);
        $this->assertNotEquals(0, $stat['ctime']);

        $file->setAccessTime(10);
        $file->setModificationTime(10);
        $file->setChangeTime(10);

        file_get_contents($fs->path('/file'));
        $stat = stat($fs->path('/file'));

        $this->assertNotEquals(10, $stat['atime'], 'Access time has changed after read');
        $this->assertEquals(10, $stat['mtime'], 'Modification time has not changed after read');
        $this->assertEquals(10, $stat['ctime'], 'inode change time has not changed after read');

        $file->setAccessTime(10);
        $file->setModificationTime(10);
        $file->setChangeTime(10);

        file_put_contents($fs->path('/file'), 'data');
        $stat = stat($fs->path('/file'));

        $this->assertEquals(10, $stat['atime'], 'Access time has not changed after write');
        $this->assertNotEquals(10, $stat['mtime'], 'Modification time has changed after write');
        $this->assertNotEquals(10, $stat['ctime'], 'inode change time has changed after write');

        $file->setAccessTime(10);
        $file->setModificationTime(10);
        $file->setChangeTime(10);

        chmod($fs->path('/file'), 0777);
        $stat = stat($fs->path('/file'));

        $this->assertEquals(10, $stat['atime'], 'Access time has not changed after inode change');
        $this->assertEquals(10, $stat['mtime'], 'Modification time has not changed after inode change');
        $this->assertNotEquals(10, $stat['ctime'], 'inode change time has changed after inode change');

        $file->setAccessTime(10);
        $file->setModificationTime(10);
        $file->setChangeTime(10);

        clearstatcache();

        fopen($fs->path('/file'), 'r');
        $stat = stat($fs->path('/file'));

        $this->assertEquals(10, $stat['atime'], 'Access time has not changed after opening for reading');
        $this->assertEquals(10, $stat['mtime'], 'Modification time has not changed after opening for reading');
        $this->assertEquals(10, $stat['ctime'], 'inode change time has not changed after opening for reading');

        $file->setAccessTime(20);
        $file->setModificationTime(20);
        $file->setChangeTime(20);

        fopen($fs->path('/file'), 'w');
        $stat = stat($fs->path('/file'));

        $this->assertEquals(20, $stat['atime'], 'Access time has not changed after opening for writing');
        $this->assertNotEquals(20, $stat['mtime'], 'Modification time has changed after opnening for writing');
        $this->assertNotEquals(20, $stat['ctime'], 'inode change time has changed after opnening for writing');

    }

    public function testTouchFileCreation()
    {
        $fs = new FileSystem();

        touch($fs->path('/file2'));

        $this->assertTrue(file_exists($fs->path('/file2')));

        @touch($fs->path('/dir/file'));

        $error = error_get_last();

        $this->assertStringMatchesFormat(
            'touch: %s: No such file or directory.',
            $error['message'],
            'Fails when no parent'
        );

        $file = $fs->container()->fileAt('/file2');

        $file->setAccessTime(20);
        $file->setModificationTime(20);
        $file->setChangeTime(20);

        touch($fs->path('/file2'));
        $stat = stat($fs->path('/file2'));

        $this->assertNotEquals(20, $stat['atime'], 'Access time has changed after touch');
        $this->assertNotEquals(20, $stat['mtime'], 'Modification time has changed after touch');
        $this->assertNotEquals(20, $stat['ctime'], 'inode change time has changed after touch');

    }

}
