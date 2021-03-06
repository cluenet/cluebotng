<?PHP
	class Feed {
		public static $host = 'irc.wikimedia.org';
		public static $port = 6667;
		public static $channel = '#en.wikipedia';
		private static $fd;
		public static function connectLoop() {
			self::$fd = fsockopen( self::$host, self::$port, $feederrno, $feederrstr, 30 );

			if( !self::$fd )
				return;
			
			$nick = str_replace( ' ', '_', Config::$user );

			self::send( 'USER ' . $nick . ' "1" "1" :ClueBot Wikipedia Bot 2.0.' );
			self::send( 'NICK ' . $nick );

			while( !feof( self::$fd ) ) {
				$rawline = fgets( self::$fd, 1024 );
				$line = str_replace( Array( "\n", "\r" ), '', $rawline );
				if( !$line ) {
					fclose( self::$fd );
					break;
				}
				self::loop( $line );
			}
		}
		
		public static function bail( $change, $why = '', $score = 'N/A', $reverted = false ) {
			$udp = fsockopen( 'udp://' . Config::$udphost, Config::$udpport );
			fwrite( $udp, $change[ 'rawline' ] . "\003 # " . $score . ' # ' . $why . ' # ' . ( $reverted ? 'Reverted' : 'Not reverted' ) );
			fclose( $udp );
		}
		
		private static function loop( $line ) {
			$d = IRC::split( $line );
			
			if( $d === null )
				return;
			
			if( $d[ 'type' ] == 'direct' )
				switch( $d[ 'command' ] ) {
					case 'ping':
						self::send( 'PONG :' . $d[ 'pieces' ][ 0 ] );
						break;
				}
			else
				switch( $d[ 'command' ] ) {
					case '376':
					case '422':
						self::send( 'JOIN ' . self::$channel );
						break;
					case 'privmsg':
						if( strtolower( $d[ 'target' ] ) == self::$channel ) {
							$rawmessage = $d[ 'pieces' ][ 0 ];
							
							$message = str_replace( "\002", '', $rawmessage );
							$message = preg_replace( '/\003(\d\d?(,\d\d?)?)?/', '', $message );
							
							$data = parseFeed( $message );
							
							if( $data === false )
								return;
							
							$data[ 'line' ] = $message;
							$data[ 'rawline' ] = $rawmessage;
							
							if( stripos( 'N', $data[ 'flags' ] ) !== false ) {
								self::bail( $data, 'New article' );
								return;
							}

							$stalkchannel = array();

							foreach( Globals::$stalk as $key => $value )
								if( myfnmatch( str_replace( '_', ' ', $key ), str_replace( '_', ' ', $data[ 'user' ] ) ) )
									$stalkchannel = array_merge( $stalkchannel, explode( ',', $value ) );

							foreach( Globals::$edit as $key => $value )
								if( myfnmatch( str_replace( '_', ' ', $key ), str_replace( '_', ' ', ( $data[ 'namespace' ] == 'Main:' ? '' : $data[ 'namespace' ] ) . $data[ 'title' ] ) ) )
									$stalkchannel = array_merge( $stalkchannel, explode( ',', $value ) );

							$stalkchannel = array_unique( $stalkchannel );

							foreach( $stalkchannel as $chan )
								IRC::say(
									$chan, 'New edit: [[' . ( $data[ 'namespace' ] == 'Main:' ? '' : $data[ 'namespace' ] ) . $data[ 'title' ] . ']] http://en.wikipedia.org/w/index.php?title=' .
									urlencode( $data[ 'namespace' ] . $data[ 'title' ] ) . '&diff=prev&oldid=' . urlencode( $data[ 'revid' ] ) . ' * ' . $data[ 'user' ] .
									' * ' . $data[ 'comment' ]
								);

							switch( $data[ 'namespace' ] . $data[ 'title' ] ) {
								case 'User:' . Config::$user . '/Run':
									Globals::$run = API::$q->getpage( 'User:' . Config::$user . '/Run' );
									break;
								case 'Wikipedia:Huggle/Whitelist';
									Globals::$wl = API::$q->getpage( 'Wikipedia:Huggle/Whitelist' );
									break;
								case 'User:' . Config::$user . '/Optin':
									Globals::$optin = API::$q->getpage( 'User:' . Config::$user . '/Optin' );
									break;
								case 'User:' . Config::$user . '/AngryOptin':
									Globals::$aoptin = API::$q->getpage( 'User:' . Config::$user . '/AngryOptin' );
									break;
							}
							
							if(
								( $data[ 'namespace' ] != 'Main:' )
								and ( ( !preg_match( '/\* \[\[(' . preg_quote( $data[ 'namespace' ] . $data[ 'title' ], '/' ) . ')\]\] \- .*/i', Globals::$optin ) ) )
//								and ( $change[ 'flags' ] != 'move' )
//								and ( $change[ 'namespace' ] != 'Template:')
							) {
								self::bail( $data, 'Outside of valid namespaces' );
								return;
							}
							
							echo 'Processing: ' . $message . "\n";
							Process::processEdit( $data );
						}
						break;
				}
		}
		
		public static function send( $line ) {
			fwrite( self::$fd, $line . "\n" );
		}
	}
?>
