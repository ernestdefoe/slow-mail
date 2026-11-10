<?php

namespace ErnestDefoe\SlowMail;

use RuntimeException;

/** The key file is gone while letters written under it remain (Vault). */
class KeyMissing extends RuntimeException
{
}
