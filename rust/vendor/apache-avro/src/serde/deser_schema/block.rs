// Licensed to the Apache Software Foundation (ASF) under one
// or more contributor license agreements.  See the NOTICE file
// distributed with this work for additional information
// regarding copyright ownership.  The ASF licenses this file
// to you under the Apache License, Version 2.0 (the
// "License"); you may not use this file except in compliance
// with the License.  You may obtain a copy of the License at
//
//   http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing,
// software distributed under the License is distributed on an
// "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
// KIND, either express or implied.  See the License for the
// specific language governing permissions and limitations
// under the License.

use std::{
    borrow::Borrow,
    io::{self, Read},
};

use serde::de::{DeserializeSeed, MapAccess, SeqAccess};

use super::{Config, SchemaAwareDeserializer};
use crate::schema::MapSchema;
use crate::{Error, Schema, schema::ArraySchema, util::zag_i64};

/// Deserialize sequences from an Avro array.
pub struct BlockDeserializer<'s, 'r, R: Read, S: Borrow<Schema>> {
    reader: &'r mut R,
    schema: &'s Schema,
    config: Config<'s, S>,
    /// Track where we are in reading the array
    remaining: Option<u64>,
    block_bytes: Option<u64>,
}

// Keep negative-count blocks within their declared byte boundary while the
// official schema traversal still validates every nested value.
struct BlockReader<'a, R: Read> {
    reader: &'a mut R,
    remaining: &'a mut Option<u64>,
}
impl<R: Read> Read for BlockReader<'_, R> {
    fn read(&mut self, output: &mut [u8]) -> io::Result<usize> {
        let limit = self.remaining.map_or(output.len(), |remaining| {
            output
                .len()
                .min(usize::try_from(remaining).unwrap_or(usize::MAX))
        });
        let count = self.reader.read(&mut output[..limit])?;
        if let Some(remaining) = self.remaining {
            *remaining -= count as u64;
        }
        Ok(count)
    }
}

impl<'s, 'r, R: Read, S: Borrow<Schema>> BlockDeserializer<'s, 'r, R, S> {
    pub fn array(
        reader: &'r mut R,
        schema: &'s ArraySchema,
        config: Config<'s, S>,
    ) -> Result<Self, Error> {
        let schema = if let Schema::Ref { name } = schema.items.as_ref() {
            config.get_schema(name)?
        } else {
            &schema.items
        };
        let (remaining, block_bytes) = Self::read_block_header(reader)?;
        Ok(Self {
            reader,
            schema,
            config,
            remaining,
            block_bytes,
        })
    }

    pub fn map(
        reader: &'r mut R,
        schema: &'s MapSchema,
        config: Config<'s, S>,
    ) -> Result<Self, Error> {
        let schema = if let Schema::Ref { name } = schema.types.as_ref() {
            config.get_schema(name)?
        } else {
            &schema.types
        };
        let (remaining, block_bytes) = Self::read_block_header(reader)?;
        Ok(Self {
            reader,
            schema,
            config,
            remaining,
            block_bytes,
        })
    }

    fn read_block_header(reader: &mut R) -> Result<(Option<u64>, Option<u64>), Error> {
        let remaining = zag_i64(reader)?;
        let bytes = if remaining < 0 {
            remaining.checked_neg().ok_or_else(|| {
                <Error as serde::de::Error>::custom("Invalid Avro collection count")
            })?;
            Some(u64::try_from(zag_i64(reader)?).map_err(|_| {
                <Error as serde::de::Error>::custom("Invalid Avro collection byte length")
            })?)
        } else {
            None
        };
        if remaining == 0 {
            // If the block size is zero the array/map is finished
            Ok((None, None))
        } else {
            Ok((Some(remaining.unsigned_abs()), bytes))
        }
    }

    fn read_seed<'de, T: DeserializeSeed<'de>>(
        &mut self,
        schema: &'s Schema,
        seed: T,
    ) -> Result<T::Value, Error> {
        let mut bounded = BlockReader {
            reader: self.reader,
            remaining: &mut self.block_bytes,
        };
        // Erase the wrapper type so recursive schemas do not create an
        // unbounded chain of monomorphized reader types.
        let mut reader: &mut dyn Read = &mut bounded;
        seed.deserialize(SchemaAwareDeserializer::new(
            &mut reader,
            schema,
            self.config,
        )?)
    }

    fn finish_item(&mut self, remaining: u64) -> Result<(), Error> {
        if remaining == 0 {
            if self.block_bytes.is_some_and(|bytes| bytes != 0) {
                return Err(<Error as serde::de::Error>::custom(
                    "Avro collection block size does not match",
                ));
            }
            (self.remaining, self.block_bytes) = Self::read_block_header(self.reader)?;
        } else {
            self.remaining = Some(remaining);
        }
        Ok(())
    }
}

/// Deserialize as an array
impl<'de, 's, 'r, R: Read, S: Borrow<Schema>> SeqAccess<'de> for BlockDeserializer<'s, 'r, R, S> {
    type Error = Error;

    fn next_element_seed<T>(&mut self, seed: T) -> Result<Option<T::Value>, Self::Error>
    where
        T: DeserializeSeed<'de>,
    {
        if let Some(mut remaining) = self.remaining {
            let value = self.read_seed(self.schema, seed)?;
            remaining -= 1;
            self.finish_item(remaining)?;
            Ok(Some(value))
        } else {
            Ok(None)
        }
    }

    fn size_hint(&self) -> Option<usize> {
        self.remaining
            .map(|x| usize::try_from(x).unwrap_or(usize::MAX))
    }
}

/// Deserialize as a map
impl<'de, 's, 'r, R: Read, S: Borrow<Schema>> MapAccess<'de> for BlockDeserializer<'s, 'r, R, S> {
    type Error = Error;

    fn next_key_seed<K>(&mut self, seed: K) -> Result<Option<K::Value>, Self::Error>
    where
        K: DeserializeSeed<'de>,
    {
        if self.remaining.is_some() {
            self.read_seed(&Schema::String, seed).map(Some)
        } else {
            Ok(None)
        }
    }

    fn next_value_seed<V>(&mut self, seed: V) -> Result<V::Value, Self::Error>
    where
        V: DeserializeSeed<'de>,
    {
        let mut remaining = self
            .remaining
            .expect("next_key returned None, next_value should not have been called");
        let value = self.read_seed(self.schema, seed)?;

        remaining -= 1;
        self.finish_item(remaining)?;
        Ok(value)
    }

    fn next_entry_seed<K, V>(
        &mut self,
        kseed: K,
        vseed: V,
    ) -> Result<Option<(K::Value, V::Value)>, Self::Error>
    where
        K: DeserializeSeed<'de>,
        V: DeserializeSeed<'de>,
    {
        if let Some(mut remaining) = self.remaining {
            let key = self.read_seed(&Schema::String, kseed)?;
            let value = self.read_seed(self.schema, vseed)?;

            remaining -= 1;
            self.finish_item(remaining)?;

            Ok(Some((key, value)))
        } else {
            Ok(None)
        }
    }

    fn size_hint(&self) -> Option<usize> {
        self.remaining
            .map(|x| usize::try_from(x).unwrap_or(usize::MAX))
    }
}
