<?php

// Overload the requirements backend to our special handler.
Requirements::set_backend(new CustomRequirementsBackend());