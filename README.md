# htmluseful
Just a little experiment, you might be interrested in https://github.com/j0k3r/php-readability and https://github.com/j0k3r/graby

Extract metadata and main content from a HTML page.

## Why this code ?
Using https://github.com/chrissimpkins/tweetledee to read tweet as RSS is painful  : most of the time there is a link, and that's the real content.
So each time it's require to open a new tab. 
Worse : we don't even know where we are going to land : the http://t.co/ can be skipped but sometimes there is another layer of redirection.

The Twitter client is now using a quick way to show a Chrome page included into the client.

Wouldn't be better if the content of the page was directly included ?

That's the start of this projet : a little hack of tweetledee to display the content of the content or the metadata of a linked page.

## Improvements ( ? )
* Focus only on metadata (since there is php-readability for the content) ( ? )
* Better mapping of differents metadata
* Read oembed data : http://oembed.com/#section4
* Read applinks data : http://applinks.org/documentation/
* Read `<link rel="alternate" media="..." href="..." />`
